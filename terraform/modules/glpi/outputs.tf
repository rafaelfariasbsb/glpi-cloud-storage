output "container_app_name" {
  description = "GLPI Container App name"
  value       = azurerm_container_app.this.name
}

output "container_app_id" {
  description = "GLPI Container App ID"
  value       = azurerm_container_app.this.id
}

output "url" {
  description = "GLPI application URL"
  value       = "https://${azurerm_container_app.this.ingress[0].fqdn}"
}

output "fqdn" {
  description = "GLPI fully qualified domain name"
  value       = azurerm_container_app.this.ingress[0].fqdn
}
