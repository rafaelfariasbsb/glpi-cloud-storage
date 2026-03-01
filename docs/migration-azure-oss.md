# Migracao: league/flysystem-azure-blob-storage -> azure-oss/storage-blob-flysystem

**Data:** 2026-03-01
**Prioridade:** ALTA-02 (backlog de seguranca)
**Motivacao:** Ambos os pacotes atuais estao abandonados/retirados

---

## 1. Situacao Atual (PROBLEMATICA)

```
composer.json ATUAL:
  league/flysystem: ^3.0
  league/flysystem-azure-blob-storage: ^3.0   <-- ABANDONADO (fev/2025)
  microsoft/azure-storage-blob: ^1.5           <-- RETIRADO (mar/2024)
```

| Pacote | Status | Ultimo patch de seguranca |
|--------|--------|--------------------------|
| `league/flysystem-azure-blob-storage` | Abandonado pelo mantenedor (Frank de Jonge) em fev/2025. Packagist exibe aviso. | Sem garantia |
| `microsoft/azure-storage-blob` | Retirado pela Microsoft em 17/mar/2024. Ultima versao: 1.5.4 (set/2022) | Set/2022 |
| `microsoft/azure-storage-common` | Dep transitiva, tambem retirada | Set/2022 |

**Risco:** Qualquer CVE nesses pacotes nao sera corrigido.

---

## 2. Destino da Migracao

```
composer.json NOVO:
  league/flysystem: ^3.28
  azure-oss/storage-blob-flysystem: ^1.4
```

| Pacote | Status | Ultima versao | PHP |
|--------|--------|---------------|-----|
| `azure-oss/storage-blob-flysystem` | Mantido ativamente | 1.4.1 (dez/2025) | ^8.1 |
| `azure-oss/storage` (dep transitiva) | Mantido ativamente, 100% PHP | 1.6.0 (nov/2025) | ^8.1 |

**Cadeia de dependencias apos migracao:**
```
glpi-plugin/azureblobstorage
  -> league/flysystem ^3.28              (mesma major, sem breaking change)
  -> azure-oss/storage-blob-flysystem ^1.4
     -> azure-oss/storage ^1.4           (substitui microsoft/azure-storage-blob)
        -> guzzlehttp/guzzle ^7.8
        -> caseyamcl/guzzle_retry_middleware ^2.10
```

---

## 3. Impacto no Codigo

### 3.1 Arquivos afetados

| Arquivo | Impacto | Complexidade |
|---------|---------|-------------|
| `composer.json` | Trocar deps | Trivial |
| `src/AzureBlobClient.php` | **Reescrita do construtor + SAS + testConnection** | Alto |
| Demais arquivos (`DocumentHook`, `Config`, `DocumentTracker`, commands) | **Zero mudancas** | Nenhum |

A camada Flysystem (`$this->filesystem->writeStream()`, `->read()`, `->delete()`, `->fileExists()`) **nao muda** — mesma interface `League\Flysystem\Filesystem`.

### 3.2 Mudancas detalhadas em AzureBlobClient.php

#### a) Imports (use statements)

```php
// REMOVER (6 imports):
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\BlobSharedAccessSignatureHelper;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Common\Internal\Resources;
use MicrosoftAzure\Storage\Common\Middlewares\RetryMiddlewareFactory;

// ADICIONAR (5 imports):
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\Blob\Sas\BlobSasBuilder;
use AzureOss\Storage\Blob\Sas\BlobSasPermissions;
```

#### b) Propriedades da classe

```php
// REMOVER:
private BlobRestProxy $blobClient;

// ADICIONAR:
private BlobContainerClient $containerClient;
```

**Nota:** `$accountName` e `$accountKey` continuam necessarios para SAS (a menos que use `generateSasUri()` diretamente no client).

#### c) Construtor

```php
// ANTES (microsoft SDK):
$this->blobClient = BlobRestProxy::createBlobService($connectionString, [
    'http' => [
        'connect_timeout' => 5,
        'timeout'         => 30,
    ],
]);
$this->blobClient->pushMiddleware(
    RetryMiddlewareFactory::create(
        RetryMiddlewareFactory::GENERAL_RETRY_TYPE,
        3, 1000,
        RetryMiddlewareFactory::EXPONENTIAL_INTERVAL_ACCUMULATION,
        true
    )
);
$adapter = new AzureBlobStorageAdapter($this->blobClient, $this->containerName);

// DEPOIS (azure-oss):
$serviceClient = BlobServiceClient::fromConnectionString($connectionString);
$this->containerClient = $serviceClient->getContainerClient($this->containerName);
$adapter = new AzureBlobStorageAdapter($this->containerClient);
```

**Retry/Timeout:** O `azure-oss/storage` usa `guzzlehttp/guzzle` com `caseyamcl/guzzle_retry_middleware` como dep transitiva. Retry e automatico. Para customizar timeouts, passar `BlobServiceClientOptions` no construtor ou configurar Guzzle diretamente.

#### d) generateSasUrl()

```php
// ANTES (microsoft SDK):
$helper = new BlobSharedAccessSignatureHelper($this->accountName, $this->accountKey);
$sas = $helper->generateBlobServiceSharedAccessSignatureToken(
    Resources::RESOURCE_TYPE_BLOB,
    $this->containerName . '/' . $blobPath,
    'r',
    $expiry
);
return sprintf('%s/%s/%s?%s', $this->blobEndpoint, $this->containerName, $blobPath, $sas);

// DEPOIS (azure-oss):
$blobClient = $this->containerClient->getBlobClient($blobPath);
$sasUri = $blobClient->generateSasUri(
    BlobSasBuilder::new()
        ->setPermissions(new BlobSasPermissions(read: true))
        ->setExpiresOn($expiry)
        ->setProtocol(SasProtocol::HttpsOnly)  // Fix P1-3.3
);
return (string) $sasUri;
```

**Vantagens do novo approach:**
- API fluente, mais legivel
- `setProtocol(SasProtocol::HttpsOnly)` resolve o fix P1-3.3 automaticamente
- `setIPRange(new SasIpRange(...))` resolve o fix P2-3.7 facilmente
- Nao precisa construir a URL manualmente (evita bugs de encoding)
- Nao precisa manter `$accountName`/`$accountKey` como propriedades separadas — o client ja os tem

#### e) testConnection()

```php
// ANTES:
$options = new ListBlobsOptions();
$options->setMaxResults(1);
$this->blobClient->listBlobs($this->containerName, $options);

// DEPOIS:
// getBlobs() retorna um generator/iterable — pegar apenas o primeiro
$blobs = $this->containerClient->getBlobs();
// Iterar apenas 1 para validar acesso
foreach ($blobs as $blob) {
    break;
}
```

**Nota:** Verificar se `getBlobs()` aceita options para `maxResults`. Se nao, o generator e lazy por natureza — iterar 1 e parar nao carrega todos.

#### f) parseBlobEndpoint()

Pode ser **removida** se usarmos `$blobClient->generateSasUri()` em vez de construir a URL manualmente. O novo SDK encapsula toda a logica de endpoint.

---

## 4. Mudancas no composer.json

```json
{
    "require": {
        "php": ">=8.2",
        "league/flysystem": "^3.28",
        "azure-oss/storage-blob-flysystem": "^1.4"
    }
}
```

**Removidos:**
- `league/flysystem-azure-blob-storage` (abandonado)
- `microsoft/azure-storage-blob` (retirado)

**Nota:** `azure-oss/storage` vem como dep transitiva via `azure-oss/storage-blob-flysystem`.

---

## 5. Compatibilidade com Azurite (Dev Local)

O `azure-oss/storage` suporta connection strings do Azurite:
```
DefaultEndpointsProtocol=http;AccountName=devstoreaccount1;AccountKey=Eby8vdM...;BlobEndpoint=http://azurite:10000/devstoreaccount1;
```

O `BlobServiceClient::fromConnectionString()` parseia `BlobEndpoint` explicitamente, assim como o SDK antigo. Sem breaking change no dev local.

---

## 6. Riscos da Migracao

| Risco | Probabilidade | Mitigacao |
|-------|--------------|-----------|
| API do `azure-oss/storage` diferente do esperado | Baixa | Verificar com `composer require` + testes locais antes de commitar |
| Comportamento de retry diferente | Media | O novo SDK usa `guzzle_retry_middleware` — testar cenarios de falha com Azurite |
| SAS URL format diferente | Baixa | Ambos geram SAS padrao Azure — testar download via SAS |
| `getBlobs()` sem `maxResults` para testConnection | Media | Verificar docs; se nao tiver, o generator e lazy (iterar 1 e break) |
| Docker Compose precisa rebuild | Trivial | `composer install` dentro do container |

---

## 7. Checklist de Implementacao

- [ ] `composer remove league/flysystem-azure-blob-storage microsoft/azure-storage-blob`
- [ ] `composer require azure-oss/storage-blob-flysystem:^1.4`
- [ ] Reescrever `AzureBlobClient.php` (construtor, SAS, testConnection)
- [ ] Remover `parseBlobEndpoint()` (se SAS via client)
- [ ] Testar upload com Azurite (`docker compose up`)
- [ ] Testar download SAS redirect com Azurite
- [ ] Testar download proxy com Azurite
- [ ] Testar delete
- [ ] Testar deduplication (upload 2 docs com mesmo conteudo)
- [ ] Testar `testConnection()` na config page
- [ ] Testar `plugins:azureblobstorage:migrate --dry-run`
- [ ] Testar `plugins:azureblobstorage:migrate-local --dry-run`
- [ ] Atualizar `docs/architecture.md` (dependencias)
- [ ] Atualizar `docs/development-guide.md` (dependencias)
- [ ] Commit + push

---

## 8. Bonus: Fixes de Seguranca que a Migracao Resolve Automaticamente

| Fix | Como |
|-----|------|
| **P1-3.3** Forcar HTTPS no SAS | `BlobSasBuilder::setProtocol(SasProtocol::HttpsOnly)` |
| **P2-3.7** IP restriction no SAS | `BlobSasBuilder::setIPRange(new SasIpRange(...))` nativo |
| **ALTA-02** Deps abandonadas | Eliminadas completamente |
| **BAIXA-05** sanitizeErrorMessage | Novo SDK tem mensagens de erro diferentes — revisar regex |
