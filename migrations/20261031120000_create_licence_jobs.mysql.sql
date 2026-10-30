-- Licence extraction queue: one job per document in the incoming folder.
-- Timestamps are unix seconds (portable across drivers).
CREATE TABLE IF NOT EXISTS `licence_jobs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_name` VARCHAR(255) NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'queued',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `available_at` INT UNSIGNED NOT NULL,
    `reserved_at` INT UNSIGNED NULL,
    `records_added` INT UNSIGNED NOT NULL DEFAULT 0,
    `duplicates` INT UNSIGNED NOT NULL DEFAULT 0,
    `error` TEXT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    `updated_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_licence_jobs_status` (`status`, `available_at`),
    KEY `idx_licence_jobs_file` (`file_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
