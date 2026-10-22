-- One-time setup for the PRODUCTION database (run once, before the first
-- deploy that includes the 2026-10 migrations).
--
-- Production's schema was created by hand, so it has no `migrations` table and
-- `php artisan migrate` would try to re-create every existing table. This script
-- creates the table and marks the original 15 migrations as already applied.
-- Afterwards `php artisan migrate --force` runs only the new migrations.
--
-- Safe to run twice: the table is created only if missing and rows are not
-- duplicated.

CREATE TABLE IF NOT EXISTS `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT m.migration, m.batch FROM (
  SELECT 'placeholder' AS migration, 0 AS batch FROM DUAL WHERE FALSE
   UNION ALL SELECT '2024_01_01_000001_create_cache_table', 1 UNION ALL SELECT '2024_01_01_000002_create_sessions_table', 1 UNION ALL SELECT '2024_01_01_000003_create_roles_table', 1 UNION ALL SELECT '2024_01_01_000004_create_jobs_and_auth_tables', 1 UNION ALL SELECT '2024_01_01_000005_create_base_tables', 1 UNION ALL SELECT '2024_01_01_000006_create_types_contacts_volunteers_tables', 1 UNION ALL SELECT '2024_01_01_000007_create_events_table', 1 UNION ALL SELECT '2024_01_01_000008_create_users_table', 1 UNION ALL SELECT '2024_01_01_000009_add_user_foreign_keys_to_events', 1 UNION ALL SELECT '2024_01_01_000010_create_event_relations_tables', 1 UNION ALL SELECT '2024_01_01_000011_create_days_and_hours_tables', 1 UNION ALL SELECT '2024_01_01_000012_create_appointments_table', 1 UNION ALL SELECT '2024_01_01_000013_create_appointments_archive_table', 1 UNION ALL SELECT '2024_01_01_000014_create_event_reports_table', 1 UNION ALL SELECT '2024_01_01_000015_create_evaluations_table', 1
) AS m
WHERE NOT EXISTS (SELECT 1 FROM `migrations` x WHERE x.migration = m.migration);
