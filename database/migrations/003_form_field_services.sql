-- Migration 003 — Assignation des questions du formulaire dynamique à des
-- prestations spécifiques (table de liaison many-to-many). Absence de ligne
-- pour un champ = ce champ s'applique à toutes les prestations (comportement
-- actuel conservé, aucune migration de données requise).
-- Idempotent : ne recrée pas la table si elle existe déjà.
-- À exécuter sur la base de production (OVH) : le projet n'a pas de runner de migration.

CREATE TABLE IF NOT EXISTS form_field_services (
    field_id   BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (field_id, service_id),
    CONSTRAINT fk_ffs_field   FOREIGN KEY (field_id)   REFERENCES form_fields(id) ON DELETE CASCADE,
    CONSTRAINT fk_ffs_service FOREIGN KEY (service_id) REFERENCES services(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
