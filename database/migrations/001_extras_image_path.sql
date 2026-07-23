-- Migration 001 — Ajoute une image aux extras (catégories et services l'ont déjà).
-- Idempotent : ne rejoue pas la colonne si elle existe déjà.
-- À exécuter sur la base de production (OVH) : le projet n'a pas de runner de migration.

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'extras'
      AND COLUMN_NAME = 'image_path'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE extras ADD COLUMN image_path VARCHAR(255) NULL AFTER icon',
    'SELECT "extras.image_path déjà présent" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
