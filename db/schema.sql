-- PSP tables. Apps can add columns to these tables (EG: jobs.customer_id)

CREATE TABLE `data_cache` (
  `data_cache_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cache_level` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cache_level_pk` bigint unsigned NOT NULL,
  `cache_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `data_type` enum('json','string') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'json',
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expiration_date` timestamp NOT NULL,
  PRIMARY KEY (`data_cache_pk`),
  UNIQUE KEY `account_data_cache_UN` (`cache_level`,`cache_level_pk`,`cache_name`),
  KEY `account_data_cache_last_updated_IDX` (`last_updated`) USING BTREE,
  KEY `account_data_cache_expiration_date_IDX` (`expiration_date`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `history` (
  `history_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
  `table` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `field` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `record` bigint unsigned NOT NULL,
  `changed_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `old_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `new_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `changed_by` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `log_ids` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`history_pk`),
  KEY `history_changed_date_index` (`changed_date`),
  KEY `history_table_record_index` (`table`,`record`,`changed_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `job_log` (
  `log_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `status` enum('Q','R','C','T') NOT NULL,
  `hostname` varchar(150) DEFAULT NULL,
  `pid` int unsigned DEFAULT NULL,
  `exit_status` tinyint unsigned DEFAULT NULL,
  `date_queued` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `date_started` timestamp NULL DEFAULT NULL,
  `date_completed` timestamp NULL DEFAULT NULL,
  `record_locked` timestamp NULL DEFAULT NULL,
  `launch_attempts` tinyint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`log_id`),
  KEY `job_log_status_record_locked_index` (`status`,`record_locked`),
  KEY `job_log_job_id_status_exit_status_idx` (`job_id`,`status`,`exit_status`),
  KEY `job_log_date_queued_index` (`date_queued`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `job_once` (
  `job_once_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(250) NOT NULL,
  `command` varchar(250) NOT NULL,
  `run_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `runtime_max_mins` smallint unsigned NOT NULL DEFAULT '120',
  `status` enum('W','Q','R','C','T') NOT NULL,
  `hostname` varchar(150) DEFAULT NULL,
  `pid` int unsigned DEFAULT NULL,
  `exit_status` tinyint unsigned DEFAULT NULL,
  `date_queued` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_started` timestamp NULL DEFAULT NULL,
  `date_completed` timestamp NULL DEFAULT NULL,
  `record_locked` timestamp NULL DEFAULT NULL,
  `launch_attempts` tinyint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`job_once_id`),
  KEY `job_once_status_record_locked_index` (`status`,`record_locked`),
  KEY `job_once_status_run_at_index` (`status`,`run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `job_schedules` (
  `job_schedule_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `bucket_type` enum('minute','hour') DEFAULT NULL,
  `minute` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `hour` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `day_of_month` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `month` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `day_of_week` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `active` enum('Y','N') DEFAULT NULL,
  `timezone` varchar(50) DEFAULT 'UTC',
  PRIMARY KEY (`job_schedule_id`),
  KEY `job_schedules_timezone_IDX` (`timezone`,`minute`,`hour`,`day_of_month`,`month`,`day_of_week`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `job_servers` (
  `job_server_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `hostname` varchar(150) NOT NULL,
  `last_heartbeat` timestamp NULL DEFAULT NULL,
  `main_process_id` varchar(75) DEFAULT NULL,
  `main_process_last_contact` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`job_server_id`),
  UNIQUE KEY `job_servers_pk` (`hostname`),
  KEY `job_servers_hostname_index` (`hostname`),
  KEY `job_servers_last_heartbeat_index` (`last_heartbeat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `jobs` (
  `job_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_name` varchar(150) NOT NULL,
  `path` varchar(250) NOT NULL,
  `command` varchar(250) NOT NULL,
  `job_schedule_id` bigint unsigned NOT NULL,
  `bucket` tinyint unsigned DEFAULT NULL,
  `sub_bucket` tinyint unsigned DEFAULT NULL,
  `allow_duplicates` enum('Y','N') DEFAULT 'N',
  `enabled` enum('Y','N') NOT NULL DEFAULT 'Y',
  `runtime_max_mins` smallint unsigned NOT NULL DEFAULT '120',
  PRIMARY KEY (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `jobs_runner` (
  `jobs_runner_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `scheduled_time` datetime NOT NULL,
  `hostname` varchar(150) NOT NULL,
  `process_id` int unsigned NOT NULL,
  `date_finished` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`jobs_runner_id`),
  UNIQUE KEY `jobs_runner_scheduled_time_uindex` (`scheduled_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `locks` (
  `lock_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lock_level` varchar(32) COLLATE utf8mb4_general_ci NOT NULL,
  `lock_level_pk` bigint unsigned NOT NULL,
  `lock_name` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `acquired_date` datetime NOT NULL,
  `released_date` datetime DEFAULT NULL,
  `from_server` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `process_id` int unsigned NOT NULL,
  PRIMARY KEY (`lock_pk`),
  UNIQUE KEY `locks_lock_level_IDX` (`lock_level`,`lock_level_pk`,`lock_name`) USING BTREE,
  KEY `locks_from_server_IDX` (`from_server`,`process_id`) USING BTREE,
  KEY `locks_acquired_date_IDX` (`acquired_date`) USING BTREE,
  KEY `locks_released_date_IDX` (`released_date`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
