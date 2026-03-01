# ─────────────────────────────────────────────
# GLPI Application
# ─────────────────────────────────────────────
output "glpi_url" {
  description = "GLPI application URL"
  value       = module.glpi.url
}

output "glpi_container_app_name" {
  description = "GLPI Container App name"
  value       = module.glpi.container_app_name
}

# ─────────────────────────────────────────────
# Database
# ─────────────────────────────────────────────
output "mariadb_container_app_name" {
  description = "MariaDB Container App name"
  value       = module.database.container_app_name
}

# ─────────────────────────────────────────────
# Storage (for Azure Blob Storage plugin config)
# ─────────────────────────────────────────────
output "storage_account_name" {
  description = "Storage Account name (for plugin config)"
  value       = module.storage.account_name
}

output "storage_container_name" {
  description = "Blob container name (for plugin config)"
  value       = module.storage.container_name
}

output "storage_connection_string" {
  description = "Storage Account connection string (for plugin config)"
  value       = module.storage.primary_connection_string
  sensitive   = true
}

output "storage_account_key" {
  description = "Storage Account primary key (for plugin config)"
  value       = module.storage.primary_access_key
  sensitive   = true
}

# ─────────────────────────────────────────────
# Networking
# ─────────────────────────────────────────────
output "resource_group_name" {
  description = "Resource Group name"
  value       = module.networking.resource_group_name
}
