CREATE TABLE IF NOT EXISTS `v2_app_domain_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `group_id` int unsigned NOT NULL,
  `cohort` varchar(32) NOT NULL,
  `round_uuid` varchar(64) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `metrics` longtext DEFAULT NULL,
  `enable` tinyint(1) NOT NULL DEFAULT 1,
  `frozen_until` int DEFAULT NULL,
  `assigned_at` int NOT NULL,
  `created_at` int DEFAULT NULL,
  `updated_at` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user` (`user_id`),
  KEY `idx_group_enable` (`group_id`, `enable`),
  KEY `idx_round_cohort` (`round_uuid`, `cohort`),
  KEY `idx_frozen_until` (`frozen_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
