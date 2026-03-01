output "account_name" {
  description = "Storage Account name"
  value       = azurerm_storage_account.this.name
}

output "account_id" {
  description = "Storage Account ID"
  value       = azurerm_storage_account.this.id
}

output "container_name" {
  description = "Blob container name"
  value       = azurerm_storage_container.this.name
}

output "primary_connection_string" {
  description = "Storage Account primary connection string"
  value       = azurerm_storage_account.this.primary_connection_string
  sensitive   = true
}

output "primary_access_key" {
  description = "Storage Account primary access key"
  value       = azurerm_storage_account.this.primary_access_key
  sensitive   = true
}
