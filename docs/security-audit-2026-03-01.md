# Relatório de Auditoria de Segurança — Plugin Azure Blob Storage

**Data**: 2026-03-01
**Escopo**: `azureblobstorage/` (plugin completo) + infraestrutura Docker
**Método**: Análise estática de código (advogado do diabo)
**Arquivos analisados**: 13 arquivos (11 PHP, 1 Twig, 1 JS) + Docker/deps

---

## Resumo Executivo

| Severidade | Qtd | Status |
|-----------|-----|--------|
| **CRÍTICA** | 0 | — |
| **ALTA** | 3 | Requer ação |
| **MÉDIA** | 7 | Recomendado corrigir |
| **BAIXA** | 8 | Melhorias opcionais |
| **INFO** | 6 | Sem ação necessária |

**Veredito**: O plugin apresenta uma postura de segurança **boa**. Nenhuma vulnerabilidade crítica encontrada. Zero SQL injection, zero XSS confirmado, zero hardcoded credentials de produção. Os achados de severidade ALTA são relacionados a falta de validação de inputs e dependência abandonada — nenhum é explorável remotamente sem acesso admin.

---

## Achados de Severidade ALTA

### ALTA-01: Campos de configuração livres sem validação de formato

- **Arquivo**: `front/config.form.php` linhas 33-54
- **Categoria**: Input Validation
- **Descrição**: Os campos `connection_string`, `account_name`, `account_key` e `container_name` são aceitos de `$_POST` sem validação de formato ou comprimento. Embora sejam salvos via prepared statements (sem risco de SQL injection) e o acesso requer `Session::checkRight('config', UPDATE)`, valores malformados podem:
  - Causar erros imprevisíveis na API Azure
  - Armazenar payloads extremamente grandes no banco
  - `container_name` com caracteres inválidos causaria falhas silenciosas
- **Pré-requisito para explorar**: Acesso admin ao GLPI
- **Correção sugerida**:
```php
$validations = [
    'container_name' => fn($v) => preg_match('/^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?$/', $v),
    'account_name'   => fn($v) => preg_match('/^[a-z0-9]{3,24}$/', $v),
    'connection_string' => fn($v) => strlen($v) <= 4096 && (
        str_starts_with($v, 'DefaultEndpointsProtocol=') ||
        str_starts_with($v, 'UseDevelopmentStorage=')
    ),
    'account_key' => fn($v) => strlen($v) <= 512,
];
```

### ALTA-02: Dependência `league/flysystem-azure-blob-storage` ABANDONADA

- **Arquivo**: `composer.lock` linha 455
- **Categoria**: Supply Chain
- **Descrição**: O pacote `league/flysystem-azure-blob-storage` v3.31.0 está marcado como **abandoned** em favor de `azure-oss/storage-blob-flysystem`. Pacotes abandonados não recebem patches de segurança. Adicionalmente, `microsoft/azure-storage-blob` v1.5.4 e `microsoft/azure-storage-common` v1.5.2 têm 3-4 anos sem updates.
- **Correção sugerida**: Planejar migração para `azure-oss/storage-blob-flysystem` + novo SDK Azure para PHP.

### ALTA-03: SAS URL redirect sem validação de domínio

- **Arquivo**: `front/document.send.php` linha 73
- **Categoria**: Open Redirect (controlado)
- **Descrição**: O redirect 302 para SAS URL é gerado internamente a partir de `$blobPath` (que vem do banco). Se a tabela `documenttrackers` for comprometida (via outro vetor), um `azure_blob_name` malicioso poderia gerar redirect para URL arbitrária. O risco prático é **baixo** (requer comprometimento do DB), mas a defesa em profundidade recomenda validar.
- **Correção sugerida**:
```php
$sasUrl = $client->generateSasUrl($blobPath, Config::getSasExpiryMinutes());
$parsedHost = parse_url($sasUrl, PHP_URL_HOST);
if ($parsedHost === null || $parsedHost === false) {
    throw new \RuntimeException('Generated SAS URL is invalid');
}
```

---

## Achados de Severidade MÉDIA

### MEDIA-01: Ausência de validação de path traversal

- **Arquivos**: `DocumentHook.php:32`, `MigrateCommand.php:139`, `MigrateLocalCommand.php:102`, `AzureBlobClient.php:186`
- **Categoria**: Path Traversal
- **Descrição**: `GLPI_DOC_DIR . '/' . $filepath` é construído sem verificar se o path resultante está dentro de `GLPI_DOC_DIR`. O `$filepath` vem do campo `filepath` do Document (gerado pelo core GLPI no formato `EXT/XX/sha1.EXT`), mas se o DB for corrompido, poderia haver traversal. No `MigrateLocalCommand`, `downloadToFile()` criaria diretórios e escreveria arquivos fora de GLPI_DOC_DIR.
- **Correção sugerida**: Criar helper de validação:
```php
public static function validateLocalPath(string $filepath): string
{
    $localPath = GLPI_DOC_DIR . '/' . $filepath;
    $realDocDir = realpath(GLPI_DOC_DIR);
    $checkPath = file_exists($localPath) ? realpath($localPath) : realpath(dirname($localPath));
    if ($realDocDir === false || $checkPath === false || !str_starts_with($checkPath, $realDocDir)) {
        throw new \RuntimeException('[AzureBlobStorage] Path traversal detected: ' . $filepath);
    }
    return $localPath;
}
```

### MEDIA-02: Race conditions em upload/purge de documentos

- **Arquivo**: `DocumentHook.php` linhas 39-49 (onItemAdd) e 224-225 (onPreItemPurge)
- **Categoria**: Race Condition
- **Descrição**:
  - **Upload**: A verificação de deduplicação (`sha1ExistsInAzure` + `$client->exists`) não tem lock. Dois uploads simultâneos do mesmo arquivo podem criar tracking records duplicados.
  - **Purge**: O tracker é removido ANTES de verificar se pode deletar o blob. Um `onItemAdd` entre a remoção e a verificação pode causar estado inconsistente.
- **Impacto**: Janela de race muito pequena. Pior caso é re-upload (não perda de dados). O código já reconhece isso em comentários.
- **Correção sugerida**: Usar transação DB: `$DB->beginTransaction()` ... `$DB->commit()`.

### MEDIA-03: Filename sem escape em flash message

- **Arquivo**: `front/document.send.php` linha 59
- **Categoria**: XSS (potencial)
- **Descrição**: `$doc->fields['filename']` é interpolado em `sprintf(__('File %s not found.'))` e passado para `setMessageToDisplay()` sem `htmlescape()`. Se o filename contiver HTML e o GLPI renderizar a flash message sem escaping, haveria XSS stored.
- **Correção**: `$exception->setMessageToDisplay(htmlescape(sprintf(__('File %s not found.'), $doc->fields['filename'])));`

### MEDIA-04: `.gitignore` minimalista no plugin

- **Arquivo**: `azureblobstorage/.gitignore`
- **Descrição**: Contém apenas `**/vendor/`. Falta proteção contra commits acidentais de `.env`, `.idea/`, `.vscode/`, `.DS_Store`, `phpunit.xml`, `composer.phar`.
- **Correção**: Expandir o `.gitignore`.

### MEDIA-05: Ausência de `.dockerignore`

- **Descrição**: Não existe `.dockerignore` no plugin. Se um Dockerfile for adicionado futuramente, pode copiar `.git/`, `vendor/`, ou credenciais acidentalmente.

### MEDIA-06: Azure SDK PHP desatualizado

- **Descrição**: `microsoft/azure-storage-blob` v1.5.4 (Sep 2022) e `microsoft/azure-storage-common` v1.5.2 (Oct 2021) são da geração anterior do SDK. Suportam PHP >=5.6.
- **Correção**: Acompanha a migração de ALTA-02.

### MEDIA-07: Container GLPI potencialmente roda como root

- **Arquivo**: `docker-compose.yml` linha 12
- **Descrição**: A imagem `glpi/glpi:latest` não especifica user não-root. Depende da configuração upstream.
- **Correção**: Verificar e adicionar `user: "www-data"` se necessário.

---

## Achados de Severidade BAIXA

### BAIXA-01: `trigger_error` com `$DB->error()` no hook.php
- **Arquivo**: `hook.php:29-31`
- **Descrição**: Pode expor detalhes de schema do banco nos logs PHP.
- **Correção**: Usar `Toolbox::logInFile()` com mensagem sanitizada.

### BAIXA-02: Stack traces em logs de erro
- **Arquivos**: `document.send.php:117-129`, `Config.php:73-78`
- **Descrição**: Stack traces completos logados em `azureblobstorage.log` podem conter caminhos internos.
- **Correção**: Garantir que `files/_log/` não é acessível via web.

### BAIXA-03: Path de cleanup com trailing slash inconsistente
- **Arquivo**: `DocumentHook.php:346-355`
- **Descrição**: `cleanEmptyDirs()` compara `$dir !== $docDir` sem normalizar trailing slashes.
- **Correção**: `rtrim($dir, '/') !== rtrim($docDir, '/')`.

### BAIXA-04: `batch-size` sem valor mínimo nos CLI commands
- **Arquivos**: `MigrateCommand.php:47`, `MigrateLocalCommand.php:46`
- **Descrição**: Valor 0 ou negativo causaria comportamento indefinido.
- **Correção**: `$batchSize = max(1, $batchSize)`.

### BAIXA-05: Sanitização de erro incompleta no AzureBlobClient
- **Arquivo**: `AzureBlobClient.php:330-337`
- **Descrição**: Regex genérica para base64 pode não capturar patterns como `AccountKey=...`.
- **Correção**: Adicionar regexes específicas para `AccountKey=`, `SharedAccessSignature=`.

### BAIXA-06: Credenciais decifradas transitam pelo HTML do template
- **Arquivo**: `templates/config.html.twig:29,39`
- **Descrição**: `connection_string` e `account_key` são enviados decifrados ao template (mascarados como password fields). Um View Source revela os valores.
- **Correção**: Considerar pattern de placeholder (`********`) e só aceitar novos valores.

### BAIXA-07: Portas Azurite desnecessárias expostas
- **Arquivo**: `docker-compose.yml:61-63`
- **Descrição**: Portas 10001 (Queue) e 10002 (Table) expostas em 0.0.0.0, mas não são usadas.
- **Correção**: Expor apenas `127.0.0.1:10000:10000`.

### BAIXA-08: DocumentTracker com `$rightname = 'config'`
- **Arquivo**: `DocumentTracker.php:10`
- **Descrição**: Qualquer usuário com permissão de config pode manipular trackers via API genérica.
- **Correção**: Sobrescrever `canCreate()`, `canUpdate()`, `canPurge()` para restringir.

---

## Achados INFORMATIVOS

| # | Descrição |
|---|-----------|
| INFO-01 | MutationObserver com escopo amplo no `url-rewriter.js` (filtrado por regex restritivo — sem risco) |
| INFO-02 | Credenciais Azurite well-known na documentação (esperado) |
| INFO-03 | CI MySQL sem senha root (ambiente efêmero) |
| INFO-04 | `www-data` com sudo NOPASSWD no Dockerfile dev (não-produção) |
| INFO-05 | Sem `require-dev` no `composer.json` do plugin |
| INFO-06 | Decriptação falha retorna valor raw (pode causar erros confusos) |

---

## Cobertura de Segurança por Categoria

| Categoria | Avaliação | Notas |
|-----------|-----------|-------|
| SQL Injection | **Completa** | Query builder do GLPI em todos os pontos |
| XSS | **Boa** | Twig auto-escaping + htmlescape() (1 ponto parcial) |
| CSRF | **Completa** | GLPI 11 CheckCsrfListener + csrf_token() |
| Autenticação | **Completa** | Session::checkRight, canViewFile |
| Autorização | **Completa** | Segregação admin vs usuário |
| Path Traversal | **Ausente** | Confia no formato do core GLPI |
| Input Validation | **Parcial** | Enums validados, campos livres não |
| Info Disclosure | **Boa** | Sanitização de erros, mensagens genéricas |
| Hardcoded Creds | **Nenhuma** | Tudo via SECURED_CONFIGS |
| Criptografia | **Adequada** | AES-256 (GLPIKey), HMAC-SHA256 (SAS) |
| Race Conditions | **Parcial** | Reconhecidas, janela pequena |
| Error Handling | **Excelente** | Try/catch, logging, fallbacks |
| Supply Chain | **Atenção** | Dependência abandonada |

---

## Pontos Fortes do Plugin

1. **Zero modificações no core GLPI** — toda integração via hooks
2. **Credenciais criptografadas** via SECURED_CONFIGS (AES-256)
3. **Retry exponencial** com timeouts (3x, 1s/2s/4s, connect=5s, timeout=30s)
4. **Sanitização de erros** que redacta credenciais em logs
5. **Deduplicação inteligente** com verificação de existência real no Azure
6. **Deleção deferida** de arquivos locais (shutdown function)
7. **Fallback para local** quando Azure falha
8. **Proteção contra uninstall** com documentos rastreados
9. **Modo dry-run** nos comandos de migração
10. **Input validation com whitelist** para campos enumerados
11. **IIFE com strict mode** no JavaScript
12. **Content-Disposition RFC 6266** com sanitização de filename

---

## Plano de Ação Recomendado

### Prioridade 1 (Próxima sprint)
- [ ] ALTA-01: Adicionar validação de formato para campos de config livres
- [ ] ALTA-03: Validar SAS URL antes de redirect
- [ ] MEDIA-01: Implementar helper de validação de path traversal
- [ ] MEDIA-03: Aplicar `htmlescape()` no filename da flash message

### Prioridade 2 (Próxima release)
- [ ] ALTA-02: Planejar migração de `league/flysystem-azure-blob-storage` → `azure-oss/storage-blob-flysystem`
- [ ] BAIXA-05: Melhorar sanitização de erros (patterns específicos)
- [ ] BAIXA-06: Implementar placeholder pattern para credenciais no template
- [ ] MEDIA-04: Expandir `.gitignore`

### Prioridade 3 (Backlog)
- [ ] MEDIA-02: Avaliar transações DB para race conditions
- [ ] BAIXA-04: Validar `batch-size` mínimo nos CLI commands
- [ ] BAIXA-08: Restringir `$rightname` no DocumentTracker
- [ ] MEDIA-05: Criar `.dockerignore`
- [ ] BAIXA-07: Restringir portas Azurite ao localhost

---

*Relatório gerado por análise estática automatizada. Recomenda-se validação manual dos achados de severidade ALTA antes de implementar correções.*
