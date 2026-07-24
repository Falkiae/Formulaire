-- Migration 002 — Ajoute un badge de mise en avant aux prestations (texte libre,
-- ex. "Plus demandée", "La plus complète"), affiché sur la carte de la prestation
-- dans le tunnel public.
-- Idempotent : ne rejoue pas la colonne si elle existe déjà.
-- À exécuter sur la base de production (OVH) : le projet n'a pas de runner de migration.

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'services'
      AND COLUMN_NAME = 'badge_label'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE services ADD COLUMN badge_label VARCHAR(40) NULL AFTER short_description',
    'SELECT "services.badge_label déjà présent" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
