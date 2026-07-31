-- Migration 007 — Lignes de commande « sur mesure ».
--
-- `booking_items.service_id` était NOT NULL avec une clé étrangère vers
-- `services` : impossible d'ajouter à une commande une prestation hors
-- catalogue (supplément négocié, travail exceptionnel, geste commercial).
-- La colonne devient NULLABLE — une ligne sur mesure porte alors son libellé
-- dans `label_snapshot` et son prix dans `unit_price_cents`, sans référence au
-- catalogue.
--
-- La clé étrangère est conservée : une ligne QUI RÉFÉRENCE une prestation la
-- référence toujours valablement (MySQL n'applique pas la contrainte aux NULL).
--
-- À exécuter sur la base de production (OVH) : le projet n'a pas de runner de
-- migration. Rejouable sans dommage (l'ALTER est idempotent en pratique : il
-- réapplique la même définition).

ALTER TABLE booking_items
  MODIFY service_id BIGINT UNSIGNED NULL;
