# ─────────────────────────────────────────────
# Persistent Volume (Azure File Share for MariaDB data)
# ─────────────────────────────────────────────
resource "azurerm_storage_account" "this" {
  name                     = replace("st${var.prefix}vol", "-", "")
  resource_group_name      = var.resource_group_name
  location                 = var.location
  account_tier             = "Standard"
  account_replication_type = "LRS"
  min_tls_version          = "TLS1_2"
  tags                     = var.tags
}

resource "azurerm_storage_share" "this" {
  name               = "mariadb-data"
  storage_account_id = azurerm_storage_account.this.id
  quota              = var.volume_quota_gb
}

resource "azurerm_container_app_environment_storage" "this" {
  name                         = "mariadb-data"
  container_app_environment_id = var.container_app_environment_id
  account_name                 = azurerm_storage_account.this.name
  share_name                   = azurerm_storage_share.this.name
  access_key                   = azurerm_storage_account.this.primary_access_key
  access_mode                  = "ReadWrite"
}

# ─────────────────────────────────────────────
# Container App: MariaDB
# ─────────────────────────────────────────────
resource "azurerm_container_app" "this" {
  name                         = "ca-${var.prefix}-db"
  resource_group_name          = var.resource_group_name
  container_app_environment_id = var.container_app_environment_id
  revision_mode                = "Single"
  tags                         = var.tags

  secret {
    name  = "db-root-password"
    value = var.admin_password
  }

  secret {
    name  = "db-password"
    value = var.admin_password
  }

  template {
    min_replicas = 1
    max_replicas = 1

    container {
      name   = "mariadb"
      image  = var.image
      cpu    = var.cpu
      memory = var.memory

      env {
        name        = "MARIADB_ROOT_PASSWORD"
        secret_name = "db-root-password"
      }
      env {
        name  = "MARIADB_DATABASE"
        value = var.database_name
      }
      env {
        name  = "MARIADB_USER"
        value = var.admin_user
      }
      env {
        name        = "MARIADB_PASSWORD"
        secret_name = "db-password"
      }

      volume_mounts {
        name = "mariadb-data"
        path = "/var/lib/mysql"
      }
    }

    volume {
      name         = "mariadb-data"
      storage_name = azurerm_container_app_environment_storage.this.name
      storage_type = "AzureFile"
    }
  }

  ingress {
    target_port      = 3306
    transport        = "tcp"
    exposed_port     = 3306
    external_enabled = false

    traffic_weight {
      latest_revision = true
      percentage      = 100
    }
  }
}
