CREATE TABLE `grants` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `machine_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `approval` tinyint(1) NOT NULL DEFAULT '1',
  `expires_at` timestamp NOT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grants_machine_id_entry_id_action_unique` (`machine_id`,`entry_id`,`action`),
  KEY `grants_entry_id_foreign` (`entry_id`),
  CONSTRAINT `grants_entry_id_foreign` FOREIGN KEY (`entry_id`) REFERENCES `entries` (`id`),
  CONSTRAINT `grants_machine_id_foreign` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
