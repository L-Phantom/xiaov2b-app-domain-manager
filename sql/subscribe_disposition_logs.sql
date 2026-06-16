CREATE TABLE IF NOT EXISTS `v2_subscribe_disposition_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `action` varchar(32) NOT NULL,
  `from_status` varchar(32) DEFAULT NULL,
  `to_status` varchar(32) DEFAULT NULL,
  `risk_level` varchar(32) DEFAULT NULL,
  `risk_score` int unsigned DEFAULT NULL,
  `note` varchar(1000) DEFAULT NULL,
  `operator_id` int unsigned DEFAULT NULL,
  `operator_email` varchar(255) DEFAULT NULL,
  `created_at` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_action` (`action`),
  KEY `idx_to_status` (`to_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
