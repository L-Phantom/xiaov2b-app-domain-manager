CREATE TABLE IF NOT EXISTS `v2_app_domain_replace_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_uuid` varchar(64) NOT NULL,
  `old_host` varchar(255) NOT NULL,
  `new_host` varchar(255) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'applied',
  `change_count` int unsigned NOT NULL DEFAULT 0,
  `snapshot` longtext NOT NULL,
  `operator_id` int unsigned DEFAULT NULL,
  `operator_email` varchar(255) DEFAULT NULL,
  `created_at` int NOT NULL,
  `rolled_back_at` int DEFAULT NULL,
  `rollback_operator_id` int unsigned DEFAULT NULL,
  `rollback_operator_email` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_batch_uuid` (`batch_uuid`),
  KEY `idx_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
