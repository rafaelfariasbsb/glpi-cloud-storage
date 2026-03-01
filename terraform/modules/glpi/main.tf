resource "azurerm_container_app" "this" {
  name                         = "ca-${var.prefix}-app"
  resource_group_name          = var.resource_group_name
  container_app_environment_id = var.container_app_environment_id
  revision_mode                = "Single"
  tags                         = var.tags

  secret {
    name  = "db-password"
    value = var.db_password
  }

  secret {
    name  = "azure-storage-connection-string"
    value = var.storage_connection_string
  }

  secret {
    name  = "azure-storage-key"
    value = var.storage_account_key
  }

  template {
    min_replicas = var.min_replicas
    max_replicas = var.max_replicas

    container {
      name   = "glpi"
      image  = var.image
      cpu    = var.cpu
      memory = var.memory

      # Database configuration
      env {
        name  = "GLPI_DB_HOST"
        value = var.db_host
      }
      env {
        name  = "GLPI_DB_NAME"
        value = var.db_name
      }
      env {
        name  = "GLPI_DB_USER"
        value = var.db_user
      }
      env {
        name        = "GLPI_DB_PASSWORD"
        secret_name = "db-password"
      }

      # Azure Blob Storage configuration (for the plugin)
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
        value = var.storage_account_name
      }
      env {
        name        = "AZURE_STORAGE_ACCOUNT_KEY"
        secret_name = "azure-storage-key"
      }
    }
  }

  ingress {
    target_port      = 80
    external_enabled = true
    transport        = "auto"

    traffic_weight {
      latest_revision = true
      percentage      = 100
    }
  }
}
