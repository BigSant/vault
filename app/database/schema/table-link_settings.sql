CREATE TABLE `link_settings` (
  `workspace_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `region` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `default_lifetime` int unsigned NOT NULL,
  `max_lifetime` int unsigned NOT NULL,
  `token_saved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`workspace_id`),
  CONSTRAINT `link_settings_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
