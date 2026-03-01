output "container_app_name" {
  description = "MariaDB Container App name (used as hostname)"
  value       = azurerm_container_app.this.name
}

output "container_app_id" {
  description = "MariaDB Container App ID"
  value       = azurerm_container_app.this.id
}
