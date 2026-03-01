terraform {
  required_version = ">= 1.5"

  required_providers {
    azurerm = {
      source  = "hashicorp/azurerm"
      version = "~> 4.0"
    }
  }
}

provider "azurerm" {
  features {}
}

locals {
  prefix = "${var.project_name}-${var.environment}"
  tags = merge(var.tags, {
    project     = var.project_name
    environment = var.environment
    managed_by  = "terraform"
  })
}

# ─────────────────────────────────────────────
# Resource Group
# ─────────────────────────────────────────────
resource "azurerm_resource_group" "main" {
  name     = "rg-${local.prefix}"
  location = var.location
  tags     = local.tags
}

# ─────────────────────────────────────────────
# Storage Account + Blob Container (for GLPI documents)
# ─────────────────────────────────────────────
resource "azurerm_storage_account" "documents" {
  name                     = replace("st${local.prefix}docs", "-", "")
  resource_group_name      = azurerm_resource_group.main.name
  location                 = azurerm_resource_group.main.location
  account_tier             = "Standard"
  account_replication_type = "LRS"
  min_tls_version          = "TLS1_2"

  blob_properties {
    versioning_enabled = true
  }

  tags = local.tags
}

resource "azurerm_storage_container" "glpi_documents" {
  name                  = var.storage_container_name
  storage_account_id    = azurerm_storage_account.documents.id
  container_access_type = "private"
}

# ─────────────────────────────────────────────
# Log Analytics Workspace (required for Container Apps)
# ─────────────────────────────────────────────
resource "azurerm_log_analytics_workspace" "main" {
  name                = "log-${local.prefix}"
  resource_group_name = azurerm_resource_group.main.name
  location            = azurerm_resource_group.main.location
  sku                 = "PerGB2018"
  retention_in_days   = 30
  tags                = local.tags
}

# ─────────────────────────────────────────────
# Container Apps Environment
# ─────────────────────────────────────────────
resource "azurerm_container_app_environment" "main" {
  name                       = "cae-${local.prefix}"
  resource_group_name        = azurerm_resource_group.main.name
  location                   = azurerm_resource_group.main.location
  log_analytics_workspace_id = azurerm_log_analytics_workspace.main.id
  tags                       = local.tags
}

# ─────────────────────────────────────────────
# Container Apps Environment Storage (persistent volume for MariaDB)
# ─────────────────────────────────────────────
resource "azurerm_storage_account" "volumes" {
  name                     = replace("st${local.prefix}vol", "-", "")
  resource_group_name      = azurerm_resource_group.main.name
  location                 = azurerm_resource_group.main.location
  account_tier             = "Standard"
  account_replication_type = "LRS"
  min_tls_version          = "TLS1_2"
  tags                     = local.tags
}

resource "azurerm_storage_share" "mariadb_data" {
  name               = "mariadb-data"
  storage_account_id = azurerm_storage_account.volumes.id
  quota              = 10 # GB
}

resource "azurerm_container_app_environment_storage" "mariadb" {
  name                         = "mariadb-data"
  container_app_environment_id = azurerm_container_app_environment.main.id
  account_name                 = azurerm_storage_account.volumes.name
  share_name                   = azurerm_storage_share.mariadb_data.name
  access_key                   = azurerm_storage_account.volumes.primary_access_key
  access_mode                  = "ReadWrite"
}

# ─────────────────────────────────────────────
# Container App: MariaDB
# ─────────────────────────────────────────────
resource "azurerm_container_app" "mariadb" {
  name                         = "ca-${local.prefix}-db"
  resource_group_name          = azurerm_resource_group.main.name
  container_app_environment_id = azurerm_container_app_environment.main.id
  revision_mode                = "Single"
  tags                         = local.tags

  template {
    min_replicas = 1
    max_replicas = 1

    container {
      name   = "mariadb"
      image  = var.mariadb_image
      cpu    = 0.5
      memory = "1Gi"

      env {
        name  = "MARIADB_ROOT_PASSWORD"
        value = var.db_admin_password
      }
      env {
        name  = "MARIADB_DATABASE"
        value = var.db_name
      }
      env {
        name  = "MARIADB_USER"
        value = var.db_admin_user
      }
      env {
        name  = "MARIADB_PASSWORD"
        value = var.db_admin_password
      }

      volume_mounts {
        name = "mariadb-data"
        path = "/var/lib/mysql"
      }
    }

    volume {
      name         = "mariadb-data"
      storage_name = azurerm_container_app_environment_storage.mariadb.name
      storage_type = "AzureFile"
    }
  }

  ingress {
    external_traffic_weight {
      latest_revision = true
      percentage      = 100
    }
    target_port = 3306
    transport   = "tcp"
    exposed_port = 3306
  }
}

# ─────────────────────────────────────────────
# Container App: GLPI
# ─────────────────────────────────────────────
resource "azurerm_container_app" "glpi" {
  name                         = "ca-${local.prefix}-app"
  resource_group_name          = azurerm_resource_group.main.name
  container_app_environment_id = azurerm_container_app_environment.main.id
  revision_mode                = "Single"
  tags                         = local.tags

  template {
    min_replicas = 1
    max_replicas = 3

    container {
      name   = "glpi"
      image  = var.glpi_image
      cpu    = var.glpi_cpu
      memory = var.glpi_memory

      # GLPI database configuration
      env {
        name  = "GLPI_DB_HOST"
        value = azurerm_container_app.mariadb.name
      }
      env {
        name  = "GLPI_DB_NAME"
        value = var.db_name
      }
      env {
        name  = "GLPI_DB_USER"
        value = var.db_admin_user
      }
      env {
        name        = "GLPI_DB_PASSWORD"
        secret_name = "db-password"
      }

      # Azure Blob Storage config (for the plugin)
      env {
        name        = "AZURE_STORAGE_CONNECTION_STRING"
        secret_name = "azure-storage-connection-string"
      }
      env {
        name  = "AZURE_STORAGE_CONTAINER"
        value = var.storage_container_name
      }
      env {
        name  = "AZURE_STORAGE_ACCOUNT_NAME"
        value = azurerm_storage_account.documents.name
      }
      env {
        name        = "AZURE_STORAGE_ACCOUNT_KEY"
        secret_name = "azure-storage-key"
      }
    }
  }

  secret {
    name  = "db-password"
    value = var.db_admin_password
  }

  secret {
    name  = "azure-storage-connection-string"
    value = azurerm_storage_account.documents.primary_connection_string
  }

  secret {
    name  = "azure-storage-key"
    value = azurerm_storage_account.documents.primary_access_key
  }

  ingress {
    external_traffic_weight {
      latest_revision = true
      percentage      = 100
    }
    target_port     = 80
    external_enabled = true
    transport       = "auto"

    traffic_weight {
      latest_revision = true
      percentage      = 100
    }
  }
}
