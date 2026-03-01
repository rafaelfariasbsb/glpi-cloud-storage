# Development Guide

## Prerequisites

| Requirement | Version |
|-------------|---------|
| PHP | >= 8.2 |
| Composer | Latest |
| Docker + Docker Compose | Latest |
| GLPI (for non-Docker setup) | 11.0.x |

### Required PHP Extensions

- `ext-curl`
- `ext-json`
- `ext-openssl`

## Local Development Setup

### Option 1: Docker Compose (Recommended)

```bash
git clone https://github.com/rafaelfariasbsb/glpi-cloud-storage.git
cd glpi-cloud-storage

docker compose up -d
```

This starts:
- **GLPI** at http://localhost:8080
- **MariaDB 11.8** with persistent data
- **Azurite** (Azure Storage emulator) on ports 10000-10002
- **Azurite Init** (creates `glpi-documents` container automatically)

The plugin is auto-mounted into GLPI's plugins directory via Docker volume mount.

Azurite uses well-known development credentials:
- Account: `devstoreaccount1`
- Key: `Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==`

Install and enable the plugin:
```bash
docker compose exec glpi php bin/console plugin:install azureblobstorage -u glpi
docker compose exec glpi php bin/console plugin:enable azureblobstorage
```

### Option 2: Manual Setup

```bash
# Symlink or copy the plugin into GLPI's plugins directory
ln -s /path/to/glpi-cloud-storage /path/to/glpi/plugins/azureblobstorage

# Install PHP dependencies
cd /path/to/glpi/plugins/azureblobstorage
composer install

# Install and enable via GLPI console
php /path/to/glpi/bin/console plugin:install azureblobstorage -u glpi
php /path/to/glpi/bin/console plugin:enable azureblobstorage
```

## Build Process

```bash
# Install production dependencies only
composer install --no-dev

# Install all dependencies (including dev tools)
composer install
```

The `Makefile` includes GLPI's `PluginsMakefile.mk` for standard plugin build targets.

## Project Conventions

### Namespace

All plugin classes use PSR-4 autoloading under `GlpiPlugin\Azureblobstorage\`:

```
src/AzureBlobClient.php    → GlpiPlugin\Azureblobstorage\AzureBlobClient
src/Config.php             → GlpiPlugin\Azureblobstorage\Config
src/Console/MigrateCommand → GlpiPlugin\Azureblobstorage\Console\MigrateCommand
```

### GLPI Plugin Conventions

- `setup.php`: Plugin metadata, version, hook registration (`plugin_init_azureblobstorage()`)
- `hook.php`: Install/uninstall functions (`plugin_azureblobstorage_install()`, `plugin_azureblobstorage_uninstall()`)
- `front/`: Web-accessible endpoints (config pages, form handlers)
- `templates/`: Twig templates (prefixed with `@azureblobstorage/`)
- `public/js/`: JavaScript files injected via `Hooks::ADD_JAVASCRIPT`

### Error Handling

All plugin errors use `trigger_error()` with `E_USER_WARNING` to log without blocking GLPI core operations. The plugin should never prevent document creation, update, or deletion even if Azure is unavailable.

## Source Tree

```
azureblobstorage/                   # Plugin root (= repo root)
├── setup.php                       # [ENTRY POINT] Plugin registration, version, hook init
├── hook.php                        # Install/uninstall hooks (DB table creation/removal)
├── composer.json                   # PHP dependencies (flysystem, azure SDK)
├── Makefile                        # Includes GLPI PluginsMakefile.mk
├── docker-compose.yml              # Local dev: GLPI + MariaDB + Azurite emulator
├── README.md                       # Full project documentation
│
├── src/                            # [CORE] Plugin source code (PSR-4)
│   ├── AzureBlobClient.php         # Flysystem + Azure SDK wrapper (singleton, upload/download/SAS)
│   ├── Config.php                  # Plugin configuration management (GLPI Config API wrapper)
│   ├── DocumentHook.php            # GLPI hook handlers (onItemAdd, onItemUpdate, onPreItemPurge)
│   ├── DocumentTracker.php         # Tracking table ORM (CommonDBTM)
│   └── Console/                    # Symfony Console commands
│       ├── MigrateCommand.php      # CLI: migrate existing docs to Azure (batched, dedup-aware)
│       └── MigrateLocalCommand.php # CLI: reverse migrate from Azure back to local
│
├── front/                          # [WEB] Front controllers (GLPI routing convention)
│   ├── config.php                  # Configuration page (renders Twig template)
│   ├── config.form.php             # Configuration form handler (save + test connection)
│   └── document.send.php           # Download endpoint (SAS redirect or proxy streaming)
│
├── templates/                      # [UI] Twig templates
│   └── config.html.twig            # Configuration form (extends generic_show_form.html.twig)
│
├── public/js/                      # [FRONTEND] Client-side JavaScript
│   └── url-rewriter.js             # DOM URL rewriter (MutationObserver, rewrites docid URLs)
│
├── docs/                           # Documentation
│   ├── index.md                    # Documentation index
│   ├── architecture.md             # Technical architecture and design
│   ├── configuration.md            # Azure credentials, storage modes, download methods
│   ├── installation.md             # CLI install, Docker, web interface, uninstall
│   ├── migration.md                # Migration commands and strategy
│   ├── security.md                 # Security model and recommendations
│   ├── development-guide.md        # This file
│   └── faq.md                      # FAQ and troubleshooting
│
└── terraform/                      # [INFRA] Azure infrastructure as code (modular)
    ├── main.tf                     # Root module: wires networking → storage → database → glpi
    ├── variables.tf                # All input variables with defaults and validation
    ├── outputs.tf                  # Exposes GLPI URL, storage connection string, etc.
    ├── versions.tf                 # Terraform >= 1.5, azurerm ~> 4.0
    ├── backend.tf                  # Remote state config (ready for azurerm backend)
    └── modules/
        ├── networking/             # Resource Group, Log Analytics, Container App Env
        ├── storage/                # Azure Blob Storage Account + container
        ├── database/               # MariaDB on Container Apps + Azure File volume
        └── glpi/                   # GLPI Container App + secrets + ingress
```

## Testing

### Running Tests

```bash
# PHPUnit (from GLPI root)
make phpunit

# Parallel tests
make paratest p=8
```

### Test Infrastructure

- Tests extend `DbTestCase` (provides transaction rollback, `createItem()`, `login()`, etc.)
- `vfsStream` available for filesystem mocking
- Azurite can be used for integration tests against a real Azure-compatible API

### Key Test Targets

| Class | What to Test |
|-------|-------------|
| `DocumentHook` | Upload on add, update on file change, delete on purge, deduplication, azure_primary vs backup mode |
| `DocumentTracker` | track(), isInAzure(), sha1ExistsInAzure(), countBySha1(), removeByDocumentId() |
| `Config` | getPluginConfig(), isEnabled(), isAzurePrimary(), getDownloadMethod(), cache invalidation |
| `AzureBlobClient` | upload(), download(), generateSasUrl(), testConnection(), parseBlobEndpoint() |
| `MigrateCommand` | Batch processing, dedup handling, --dry-run, --delete-local, error recovery |

## Terraform Infrastructure

### Setup

```bash
cd terraform
cp terraform.tfvars.example terraform.tfvars
# Edit terraform.tfvars with your Azure values

terraform init
terraform plan
terraform apply
```

### Key Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `environment` | `dev` | Must be dev, staging, or prod |
| `location` | `eastus2` | Azure region |
| `db_admin_password` | (required) | MariaDB password (sensitive) |
| `storage_replication_type` | `LRS` | LRS, GRS, RAGRS, or ZRS |
| `glpi_min_replicas` | `1` | GLPI auto-scaling minimum |
| `glpi_max_replicas` | `3` | GLPI auto-scaling maximum |

### Outputs

After `terraform apply`, key outputs include:
- `glpi_url` — GLPI application URL
- `storage_connection_string` — For plugin configuration (sensitive)
- `storage_account_name` — For plugin configuration
- `storage_account_key` — For plugin configuration (sensitive)
