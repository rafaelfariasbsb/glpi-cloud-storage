variable "prefix" {
  description = "Resource name prefix"
  type        = string
}

variable "resource_group_name" {
  description = "Resource Group name"
  type        = string
}

variable "container_app_environment_id" {
  description = "Container App Environment ID"
  type        = string
}

variable "image" {
  description = "GLPI container image"
  type        = string
  default     = "glpi/glpi:latest"
}

variable "cpu" {
  description = "CPU cores for GLPI container"
  type        = number
  default     = 1.0
}

variable "memory" {
  description = "Memory for GLPI container"
  type        = string
  default     = "2Gi"
}

variable "min_replicas" {
  description = "Minimum number of replicas"
  type        = number
  default     = 1
}

variable "max_replicas" {
  description = "Maximum number of replicas"
  type        = number
  default     = 3
}

# Database connection
variable "db_host" {
  description = "Database hostname (MariaDB container app name)"
  type        = string
}

variable "db_name" {
  description = "Database name"
  type        = string
}

variable "db_user" {
  description = "Database username"
  type        = string
}

variable "db_password" {
  description = "Database password"
  type        = string
  sensitive   = true
}

# Azure Blob Storage (plugin config)
variable "storage_connection_string" {
  description = "Storage Account connection string"
  type        = string
  sensitive   = true
}

variable "storage_account_name" {
  description = "Storage Account name"
  type        = string
}

variable "storage_account_key" {
  description = "Storage Account access key"
  type        = string
  sensitive   = true
}

variable "storage_container_name" {
  description = "Blob container name"
  type        = string
}

variable "tags" {
  description = "Tags to apply to all resources"
  type        = map(string)
  default     = {}
}
