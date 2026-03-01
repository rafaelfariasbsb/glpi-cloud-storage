# ─────────────────────────────────────────────
# GLPI Application
# ─────────────────────────────────────────────
output "glpi_url" {
  description = "GLPI application URL"
  value       = "https://${azurerm_container_app.glpi.ingress[0].fqdn}"
}

output "glpi_container_app_name" {
  description = "GLPI Container App name"
  value       = azurerm_container_app.glpi.name
}

# ─────────────────────────────────────────────
# Database
# ─────────────────────────────────────────────
output "mariadb_container_app_name" {
  description = "MariaDB Container App name"
  value       = azurerm_container_app.mariadb.name
}

# ─────────────────────────────────────────────
# Storage (for Azure Blob Storage plugin)
# ─────────────────────────────────────────────
output "storage_account_name" {
  description = "Storage Account name (for plugin config)"
  value       = azurerm_storage_account.documents.name
}

output "storage_container_name" {
  description = "Blob container name (for plugin config)"
  value       = azurerm_storage_container.glpi_documents.name
}

output "storage_connection_string" {
  description = "Storage Account connection string (for plugin config)"
  value       = azurerm_storage_account.documents.primary_connection_string
  sensitive   = true
}

output "storage_account_key" {
  description = "Storage Account primary key (for plugin config)"
  value       = azurerm_storage_account.documents.primary_access_key
  sensitive   = true
}

# ─────────────────────────────────────────────
# Resource Group
# ─────────────────────────────────────────────
output "resource_group_name" {
  description = "Resource Group name"
  value       = azurerm_resource_group.main.name
}
