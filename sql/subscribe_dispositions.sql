CREATE TABLE IF NOT EXISTS `v2_subscribe_dispositions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'watch',
  `level` varchar(32) DEFAULT NULL,
  `note` varchar(1000) DEFAULT NULL,
  `operator_id` int unsigned DEFAULT NULL,
  `operator_email` varchar(255) DEFAULT NULL,
  `handled_at` int DEFAULT NULL,
  `expires_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `updated_at` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_level` (`level`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
