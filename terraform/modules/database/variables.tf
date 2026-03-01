variable "prefix" {
  description = "Resource name prefix"
  type        = string
}

variable "resource_group_name" {
  description = "Resource Group name"
  type        = string
}

variable "location" {
  description = "Azure region"
  type        = string
}

variable "container_app_environment_id" {
  description = "Container App Environment ID"
  type        = string
}

variable "image" {
  description = "MariaDB container image"
  type        = string
  default     = "mariadb:11.8"
}

variable "cpu" {
  description = "CPU cores for MariaDB container"
  type        = number
  default     = 0.5
}

variable "memory" {
  description = "Memory for MariaDB container"
  type        = string
  default     = "1Gi"
}

variable "admin_user" {
  description = "Database admin username"
  type        = string
}

variable "admin_password" {
  description = "Database admin password"
  type        = string
  sensitive   = true
}

variable "database_name" {
  description = "Database name"
  type        = string
  default     = "glpi"
}

variable "volume_quota_gb" {
  description = "Azure File Share quota in GB"
  type        = number
  default     = 10
}

variable "tags" {
  description = "Tags to apply to all resources"
  type        = map(string)
  default     = {}
}
