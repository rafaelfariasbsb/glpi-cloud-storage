locals {
  prefix = "${var.project_name}-${var.environment}"
  tags = merge(var.tags, {
    project     = var.project_name
    environment = var.environment
    managed_by  = "terraform"
  })
}

# ─────────────────────────────────────────────
# Networking: Resource Group, Log Analytics, Container App Environment
# ─────────────────────────────────────────────
module "networking" {
  source = "./modules/networking"

  prefix             = local.prefix
  location           = var.location
  log_retention_days = var.log_retention_days
  tags               = local.tags
}

# ─────────────────────────────────────────────
# Storage: Azure Blob Storage for GLPI documents
# ─────────────────────────────────────────────
module "storage" {
  source = "./modules/storage"

  prefix                     = local.prefix
  resource_group_name        = module.networking.resource_group_name
  location                   = module.networking.resource_group_location
  container_name             = var.storage_container_name
  replication_type           = var.storage_replication_type
  soft_delete_retention_days = var.storage_soft_delete_days
  tags                       = local.tags
}

# ─────────────────────────────────────────────
# Database: MariaDB on Container Apps with persistent volume
# ─────────────────────────────────────────────
module "database" {
  source = "./modules/database"

  prefix                       = local.prefix
  resource_group_name          = module.networking.resource_group_name
  location                     = module.networking.resource_group_location
  container_app_environment_id = module.networking.container_app_environment_id
  image                        = var.mariadb_image
  cpu                          = var.mariadb_cpu
  memory                       = var.mariadb_memory
  admin_user                   = var.db_admin_user
  admin_password               = var.db_admin_password
  database_name                = var.db_name
  volume_quota_gb              = var.mariadb_volume_quota_gb
  tags                         = local.tags
}

# ─────────────────────────────────────────────
# GLPI: Application on Container Apps
# ─────────────────────────────────────────────
module "glpi" {
  source = "./modules/glpi"

  prefix                       = local.prefix
  resource_group_name          = module.networking.resource_group_name
  container_app_environment_id = module.networking.container_app_environment_id
  image                        = var.glpi_image
  cpu                          = var.glpi_cpu
  memory                       = var.glpi_memory
  min_replicas                 = var.glpi_min_replicas
  max_replicas                 = var.glpi_max_replicas

  # Database connection
  db_host     = module.database.container_app_name
  db_name     = var.db_name
  db_user     = var.db_admin_user
  db_password = var.db_admin_password

  # Azure Blob Storage (plugin)
  storage_connection_string = module.storage.primary_connection_string
  storage_account_name      = module.storage.account_name
  storage_account_key       = module.storage.primary_access_key
  storage_container_name    = module.storage.container_name

  tags = local.tags
}
