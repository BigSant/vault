CREATE TABLE `entry_types` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `workspace_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fields` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `entry_types_workspace_id_key_unique` (`workspace_id`,`key`),
  CONSTRAINT `entry_types_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
