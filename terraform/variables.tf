variable "project_name" {
  description = "Project name used as prefix for all resources"
  type        = string
  default     = "glpi"
}

variable "location" {
  description = "Azure region"
  type        = string
  default     = "eastus2"
}

variable "environment" {
  description = "Environment name (dev, staging, prod)"
  type        = string
  default     = "dev"
}

# Database
variable "db_admin_user" {
  description = "MariaDB admin username"
  type        = string
  default     = "glpiadmin"
}

variable "db_admin_password" {
  description = "MariaDB admin password"
  type        = string
  sensitive   = true
}

variable "db_name" {
  description = "GLPI database name"
  type        = string
  default     = "glpi"
}

# Storage
variable "storage_container_name" {
  description = "Blob container name for GLPI documents"
  type        = string
  default     = "glpi-documents"
}

# Container App
variable "glpi_image" {
  description = "GLPI container image"
  type        = string
  default     = "glpi/glpi:latest"
}

variable "glpi_cpu" {
  description = "CPU cores for GLPI container"
  type        = number
  default     = 1.0
}

variable "glpi_memory" {
  description = "Memory (Gi) for GLPI container"
  type        = string
  default     = "2Gi"
}

variable "mariadb_image" {
  description = "MariaDB container image"
  type        = string
  default     = "mariadb:11.8"
}

variable "tags" {
  description = "Tags to apply to all resources"
  type        = map(string)
  default     = {}
}
