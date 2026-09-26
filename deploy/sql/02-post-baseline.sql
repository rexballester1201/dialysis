-- ============================================================
--  02 - TABLES THE BASELINE DOES NOT CONTAIN
-- ============================================================
--  The baseline schema predates two Laravel migrations, and on a
--  host with no shell you cannot run `php artisan migrate` to add
--  them. This file is those migrations, expressed as the SQL they
--  actually produce.
--
--  personal_access_tokens is not optional. Sanctum stores every
--  issued token here, so without it no one can sign in at all --
--  not a nurse at a chair, not a clerk at a desk. If you import
--  only one file after the baseline, import this one.
--
--  Import into the database cPanel already created for you.
--  Do NOT prepend CREATE DATABASE or USE.
-- ============================================================


-- ------------------------------------------------------------
--  Sanctum personal access tokens
-- ------------------------------------------------------------
--  DATETIME(3), never TIMESTAMP: MySQL's TIMESTAMP cannot represent
--  a date past 2038-01-19 and these records are retained 10-15
--  years. Laravel's stock Sanctum migration was rewritten for this,
--  which is why this differs from the vendor default.
--
--  device_id is this project's addition. A token is bound to the
--  tablet it was issued to; presenting it from anywhere else
--  revokes it rather than merely refusing it.

CREATE TABLE IF NOT EXISTS `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `device_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_used_at` datetime(3) DEFAULT NULL,
  `expires_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) DEFAULT NULL,
  `updated_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_device_id_index` (`device_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  Monthly quality rollup
-- ------------------------------------------------------------
--  The dashboard reads this table; `dialysis:summarise-quality`
--  rebuilds it nightly FROM v_monthly_quality, which stays the
--  definition of record. Never compute a summary independently --
--  a second implementation of "average Kt/V this month" will
--  eventually disagree with the first, and the one on the screen
--  is the one people act on.
--
--  If the scheduler is not running, this table stays empty and the
--  dashboard shows nothing. That is a visible failure rather than
--  a wrong number, which is the intended trade.

CREATE TABLE IF NOT EXISTS `monthly_quality_summaries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint unsigned NOT NULL,
  `month` date NOT NULL,
  `sessions` int unsigned NOT NULL DEFAULT '0',
  `completed` int unsigned NOT NULL DEFAULT '0',
  `missed` int unsigned NOT NULL DEFAULT '0',
  `shortened` int unsigned NOT NULL DEFAULT '0',
  `sessions_with_hypotension` int unsigned NOT NULL DEFAULT '0',
  `sessions_with_reportable_event` int unsigned NOT NULL DEFAULT '0',
  `avg_ktv` decimal(4,2) DEFAULT NULL,
  `avg_urr` decimal(4,1) DEFAULT NULL,
  `avg_idwg_kg` decimal(7,2) DEFAULT NULL,
  `avg_duration_min` int unsigned DEFAULT NULL,
  `avg_pre_sbp` int unsigned DEFAULT NULL,
  `summarised_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mqs_patient_month_uq` (`patient_id`,`month`),
  KEY `mqs_month_idx` (`month`),
  CONSTRAINT `mqs_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  The migration ledger
-- ------------------------------------------------------------
--  Laravel loads the baseline by piping it into the mysql client
--  and then recreates this table from the SchemaLoaded event. That
--  event only fires during `php artisan migrate`, so importing by
--  hand leaves no ledger -- and the next time anyone DOES get shell
--  access, `migrate` would try to run both migrations again and
--  fail on tables that already exist.
--
--  The two rows below record them as already applied.

CREATE TABLE IF NOT EXISTS `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (
  SELECT '2026_08_19_150544_create_personal_access_tokens_table' AS m, 1 AS b
  UNION ALL
  SELECT '2026_08_20_090000_create_monthly_quality_summary_table', 1
) AS incoming
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = incoming.m
);


-- ------------------------------------------------------------
--  Close the lab-result duplicate hole
-- ------------------------------------------------------------
--  The baseline declares lr_uq (patient_id, test_code, specimen_date,
--  timing), which reads like "one result per test per specimen". It is
--  not: `timing` is nullable, MySQL ignores NULLs in a unique index,
--  and most tests on a monthly panel carry no timing -- so the same
--  haemoglobin could be filed twice, leaving two different values for
--  one specimen and nobody knowing which the clinician read.
--
--  timing_key coalesces NULL to a sentinel so the key can never be
--  suppressed. This is CLAUDE.md's generated-column idiom (rule 6) used
--  in reverse: there it makes a column NULL to *disable* uniqueness.
--
--  Wrapped in information_schema guards so this file stays safe to
--  re-run -- MySQL has no ADD COLUMN IF NOT EXISTS.

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'lab_results' AND column_name = 'timing_key');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE lab_results
     ADD COLUMN timing_key VARCHAR(12)
     GENERATED ALWAYS AS (COALESCE(timing, ''unspecified'')) STORED
     AFTER timing',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_old := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = 'lab_results' AND index_name = 'lr_uq');
SET @sql := IF(@has_old > 0, 'ALTER TABLE lab_results DROP INDEX lr_uq', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_new := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = 'lab_results' AND index_name = 'lr_result_uq');
SET @sql := IF(@has_new = 0,
  'ALTER TABLE lab_results
     ADD UNIQUE KEY lr_result_uq (patient_id, test_code, specimen_date, timing_key)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_08_21_100000_close_lab_result_duplicate_hole' AS m, 1 AS b) AS incoming
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = incoming.m);
