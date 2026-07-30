-- Migration 004 — Un compte de connexion ne pilote qu'UNE fiche technicien.
--
-- TechController::technician() résout la fiche du technicien connecté par
-- « SELECT * FROM technicians WHERE user_id = :u » et ne lit qu'une ligne :
-- deux fiches pointant sur le même compte rendraient le planning affiché
-- imprévisible (l'app terrain montrerait les rendez-vous de l'une ou l'autre
-- selon l'ordre de la base). Rien n'empêchait ce doublon jusqu'ici.
--
-- MySQL autorise plusieurs NULL dans un index UNIQUE : les fiches sans compte
-- de connexion restent donc possibles en nombre.
--
-- À exécuter sur la base de production (OVH) : le projet n'a pas de runner de
-- migration. NON idempotente — si l'index existe déjà, l'ALTER échoue avec
-- « Duplicate key name », ce qui est sans danger.

-- 1. Dédoublonnage préalable : on conserve la fiche la plus ancienne (id le
--    plus petit) pour chaque compte, on délie les autres. Elles restent
--    consultables dans /admin/techniciens et peuvent être rattachées à un
--    autre compte.
UPDATE technicians t
  JOIN (
        SELECT user_id, MIN(id) AS keep_id
          FROM technicians
         WHERE user_id IS NOT NULL
         GROUP BY user_id
       ) k ON k.user_id = t.user_id
   SET t.user_id = NULL
 WHERE t.id <> k.keep_id;

-- 2. Contrainte d'unicité.
ALTER TABLE technicians
  ADD UNIQUE KEY uq_technicians_user (user_id);
