-- Add sync-related columns to clients table

ALTER TABLE `clients`
ADD COLUMN `halo_psa_client_id` VARCHAR(255) NULL AFTER `abn`,
ADD COLUMN `itglue_organization_id` VARCHAR(255) NULL AFTER `halo_psa_client_id`;

-- Add indexes for faster lookups
CREATE INDEX `idx_halo_psa_client_id` ON `clients` (`halo_psa_client_id`);
CREATE INDEX `idx_itglue_organization_id` ON `clients` (`itglue_organization_id`);
