CREATE TABLE IF NOT EXISTS `v2_app_domain_bindings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int unsigned NOT NULL,
  `enable` tinyint(1) NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `server_type` varchar(32) NOT NULL,
  `server_id` int unsigned NOT NULL,
  `port` int unsigned DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `created_at` int DEFAULT NULL,
  `updated_at` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_group_server` (`group_id`, `server_type`, `server_id`),
  KEY `idx_group_enable_sort` (`group_id`, `enable`, `sort`),
  KEY `idx_server` (`server_type`, `server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
