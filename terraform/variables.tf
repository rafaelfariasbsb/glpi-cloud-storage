# ─────────────────────────────────────────────
# General
# ─────────────────────────────────────────────
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

  validation {
    condition     = contains(["dev", "staging", "prod"], var.environment)
    error_message = "Environment must be one of: dev, staging, prod."
  }
}

variable "tags" {
  description = "Tags to apply to all resources"
  type        = map(string)
  default     = {}
}

# ─────────────────────────────────────────────
# Networking
# ─────────────────────────────────────────────
variable "log_retention_days" {
  description = "Log Analytics retention in days"
  type        = number
  default     = 30
}

# ─────────────────────────────────────────────
# Storage (Azure Blob Storage for documents)
# ─────────────────────────────────────────────
variable "storage_container_name" {
  description = "Blob container name for GLPI documents"
  type        = string
  default     = "glpi-documents"
}

variable "storage_replication_type" {
  description = "Storage replication type (LRS, GRS, RAGRS, ZRS)"
  type        = string
  default     = "LRS"
}

variable "storage_soft_delete_days" {
  description = "Blob soft delete retention in days (0 to disable)"
  type        = number
  default     = 7
}

# ─────────────────────────────────────────────
# Database (MariaDB)
# ─────────────────────────────────────────────
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

variable "mariadb_image" {
  description = "MariaDB container image"
  type        = string
  default     = "mariadb:11.8"
}

variable "mariadb_cpu" {
  description = "CPU cores for MariaDB container"
  type        = number
  default     = 0.5
}

variable "mariadb_memory" {
  description = "Memory for MariaDB container"
  type        = string
  default     = "1Gi"
}

variable "mariadb_volume_quota_gb" {
  description = "Azure File Share quota in GB for MariaDB data"
  type        = number
  default     = 10
}

# ─────────────────────────────────────────────
# GLPI Application
# ─────────────────────────────────────────────
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
  description = "Memory for GLPI container"
  type        = string
  default     = "2Gi"
}

variable "glpi_min_replicas" {
  description = "Minimum number of GLPI replicas"
  type        = number
  default     = 1
}

variable "glpi_max_replicas" {
  description = "Maximum number of GLPI replicas"
  type        = number
  default     = 3
}
