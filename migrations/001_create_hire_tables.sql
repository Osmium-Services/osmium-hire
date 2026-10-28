CREATE TABLE IF NOT EXISTS `{PREFIX}hire_groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_text` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `turnaround_days` smallint unsigned NOT NULL DEFAULT '0',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hire_groups_order` (`enabled`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}hire_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int unsigned NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `unavailable` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hire_items_group` (`group_id`,`enabled`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}hires` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_id` int unsigned NOT NULL,
  `item_id` int unsigned DEFAULT NULL,
  `customer_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `admin_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `pickup_details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `status` enum('requested','confirmed','declined','cancelled') NOT NULL DEFAULT 'requested',
  `ics_sequence` smallint unsigned NOT NULL DEFAULT '0',
  `external_refs` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_hires_reference` (`reference`),
  KEY `idx_hires_status_start` (`status`,`starts_on`),
  KEY `idx_hires_item_start` (`item_id`,`starts_on`),
  KEY `idx_hires_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
