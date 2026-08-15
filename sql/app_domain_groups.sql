CREATE TABLE IF NOT EXISTS `v2_app_domain_groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `enable` tinyint(1) NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `domain` varchar(255) NOT NULL,
  `user_group_ids` longtext DEFAULT NULL,
  `plan_ids` longtext DEFAULT NULL,
  `hide_matched_nodes` tinyint(1) NOT NULL DEFAULT 0,
  `assignment_only` tinyint(1) NOT NULL DEFAULT 0,
  `remark` varchar(255) DEFAULT NULL,
  `created_at` int DEFAULT NULL,
  `updated_at` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_enable_sort` (`enable`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
