CREATE TABLE `entry_changes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int unsigned NOT NULL,
  `event` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `changes` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `entry_changes_entry_id_version_unique` (`entry_id`,`version`),
  KEY `entry_changes_entry_id_index` (`entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
