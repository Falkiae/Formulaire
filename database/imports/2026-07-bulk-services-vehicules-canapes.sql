-- =============================================================================
-- Import en masse : services véhicule (Intérieur+Extérieur, Remise à neuf
-- Intérieur/Extérieur, Shampoing 5 sièges) et services canapé par gabarit
-- (1-3, 4-5, 6-8, +).
--
-- Décisions retenues avec l'utilisateur :
--   - Prix fournis en TVAC → convertis en HT à l'insertion (colonnes *_cents
--     stockées HT, cf. Phase AJ). Le taux est lu dynamiquement depuis
--     `settings.finance.vat_rate_bp` (repli 21 % si absent).
--   - Les 4 nouveaux services véhicule REMPLACENT les 3 services véhicule de
--     démo (désactivés, pas supprimés — préserve l'historique des commandes
--     déjà passées, si applicable).
--   - Les 4 nouveaux services canapé (par gabarit) REMPLACENT l'unique
--     service « Nettoyage canapé » de démo (variantes par nombre de places),
--     également désactivé plutôt que supprimé.
--   - Les 4 services canapé sont en mode DOMICILE UNIQUEMENT (un seul prix
--     fourni, pas de distinction atelier).
--
-- À VÉRIFIER AVANT D'EXÉCUTER :
--   - Les slugs de catégories 'vehicule' et 'canape' doivent exister (créés
--     par database/seed.sql). Si ton catalogue utilise d'autres noms/slugs,
--     adapte les deux SELECT juste en dessous.
--   - Les durées (base_duration_min) ne sont PAS fournies dans le tableau
--     d'origine : des valeurs par défaut raisonnables sont posées ci-dessous
--     (indiquées par des commentaires « DURÉE PAR DÉFAUT »). Ajuste-les
--     ensuite depuis /admin/catalogue — c'est justement pour ça que ces
--     champs sont éditables.
--   - Aucun extra (ozone, matière du canapé, etc.) n'est rattaché
--     automatiquement à ces nouveaux services — à faire depuis la fiche de
--     chaque service dans l'admin si souhaité.
--   - Script NON idempotent : ne pas l'exécuter deux fois (les slugs sont
--     UNIQUE, une deuxième exécution échouera proprement sur la contrainte,
--     mais mieux vaut ne pas réessayer sans vérifier d'abord).
--
-- Tout est encapsulé dans une transaction : en cas d'erreur, rien n'est
-- appliqué (ROLLBACK automatique si le script s'arrête en erreur avant le
-- COMMIT final — à confirmer côté client SQL utilisé, ex. phpMyAdmin exécute
-- généralement les requêtes une à une : vérifie le résultat avant de relancer).
-- =============================================================================

START TRANSACTION;

-- --- Taux de TVA courant, pour convertir TVAC → HT (mirror de Money::removeVat()) --
SET @vat_bp = (SELECT CAST(`value` AS UNSIGNED) FROM settings WHERE `key` = 'finance.vat_rate_bp');
SET @vat_bp = COALESCE(@vat_bp, 2100);

-- --- Catégories cibles (déjà existantes) -------------------------------------
SET @cat_vehicule = (SELECT id FROM service_categories WHERE slug = 'vehicule' LIMIT 1);
SET @cat_canape    = (SELECT id FROM service_categories WHERE slug = 'canape' LIMIT 1);

-- --- Désactive les anciens services de démo remplacés -------------------------
UPDATE services SET is_active = 0
 WHERE slug IN ('nettoyage-interieur-vehicule', 'nettoyage-exterieur-vehicule', 'nettoyage-complet-vehicule');

UPDATE services SET is_active = 0
 WHERE slug = 'nettoyage-canape';

-- =============================================================================
--  SERVICES VÉHICULE (domicile + atelier, variantes par gabarit)
-- =============================================================================

-- --- 1. Intérieur + Extérieur : domicile 95 € / atelier 80 € TVAC ------------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (@cat_vehicule, 'Intérieur + Extérieur', 'interieur-exterieur-vehicule', 'gabarit',
        ROUND(9500 * 10000 / (10000 + @vat_bp)), 150, 'instant', 1, 0); -- DURÉE PAR DÉFAUT
SET @svc = LAST_INSERT_ID();

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
(@svc, 'onsite',   ROUND(9500 * 10000 / (10000 + @vat_bp)), 150, 1),
(@svc, 'workshop', ROUND(8000 * 10000 / (10000 + @vat_bp)), 150, 1);

INSERT INTO service_variants (service_id, code, label, price_delta_cents, is_default, sort_order) VALUES
(@svc, 'citadine',   'Citadine',                  ROUND(0    * 10000 / (10000 + @vat_bp)), 1, 0),
(@svc, 'berline',    'Berline',                   ROUND(1000 * 10000 / (10000 + @vat_bp)), 0, 1),
(@svc, 'suv',        'SUV / 4x4',                 ROUND(1500 * 10000 / (10000 + @vat_bp)), 0, 2),
(@svc, 'utilitaire', 'Camionnette / Utilitaire',  ROUND(2500 * 10000 / (10000 + @vat_bp)), 0, 3);

-- --- 2. Remise à neuf Intérieur : domicile 180 € / atelier 155 € TVAC --------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (@cat_vehicule, 'Remise à neuf Intérieur', 'remise-a-neuf-interieur-vehicule', 'gabarit',
        ROUND(18000 * 10000 / (10000 + @vat_bp)), 240, 'instant', 1, 1); -- DURÉE PAR DÉFAUT
SET @svc = LAST_INSERT_ID();

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
(@svc, 'onsite',   ROUND(18000 * 10000 / (10000 + @vat_bp)), 240, 1),
(@svc, 'workshop', ROUND(15500 * 10000 / (10000 + @vat_bp)), 240, 1);

INSERT INTO service_variants (service_id, code, label, price_delta_cents, is_default, sort_order) VALUES
(@svc, 'citadine',   'Citadine',                  ROUND(0    * 10000 / (10000 + @vat_bp)), 1, 0),
(@svc, 'berline',    'Berline',                   ROUND(1000 * 10000 / (10000 + @vat_bp)), 0, 1),
(@svc, 'suv',        'SUV / 4x4',                 ROUND(1500 * 10000 / (10000 + @vat_bp)), 0, 2),
(@svc, 'utilitaire', 'Camionnette / Utilitaire',  ROUND(2500 * 10000 / (10000 + @vat_bp)), 0, 3);

-- --- 3. Remise à neuf Extérieur : domicile 240 € / atelier 215 € TVAC --------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (@cat_vehicule, 'Remise à neuf Extérieur', 'remise-a-neuf-exterieur-vehicule', 'gabarit',
        ROUND(24000 * 10000 / (10000 + @vat_bp)), 240, 'instant', 1, 2); -- DURÉE PAR DÉFAUT
SET @svc = LAST_INSERT_ID();

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
(@svc, 'onsite',   ROUND(24000 * 10000 / (10000 + @vat_bp)), 240, 1),
(@svc, 'workshop', ROUND(21500 * 10000 / (10000 + @vat_bp)), 240, 1);

INSERT INTO service_variants (service_id, code, label, price_delta_cents, is_default, sort_order) VALUES
(@svc, 'citadine',   'Citadine',                  ROUND(0    * 10000 / (10000 + @vat_bp)), 1, 0),
(@svc, 'berline',    'Berline',                   ROUND(1000 * 10000 / (10000 + @vat_bp)), 0, 1),
(@svc, 'suv',        'SUV / 4x4',                 ROUND(1500 * 10000 / (10000 + @vat_bp)), 0, 2),
(@svc, 'utilitaire', 'Camionnette / Utilitaire',  ROUND(4000 * 10000 / (10000 + @vat_bp)), 0, 3);

-- --- 4. Shampoing 5 sièges : domicile 80 € / atelier 65 € TVAC ---------------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (@cat_vehicule, 'Shampoing 5 sièges', 'shampoing-5-sieges', 'gabarit',
        ROUND(8000 * 10000 / (10000 + @vat_bp)), 120, 'instant', 1, 3); -- DURÉE PAR DÉFAUT
SET @svc = LAST_INSERT_ID();

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
(@svc, 'onsite',   ROUND(8000 * 10000 / (10000 + @vat_bp)), 120, 1),
(@svc, 'workshop', ROUND(6500 * 10000 / (10000 + @vat_bp)), 120, 1);

INSERT INTO service_variants (service_id, code, label, price_delta_cents, is_default, sort_order) VALUES
(@svc, 'citadine',   'Citadine',                  ROUND(0    * 10000 / (10000 + @vat_bp)), 1, 0),
(@svc, 'berline',    'Berline',                   ROUND(0    * 10000 / (10000 + @vat_bp)), 0, 1),
(@svc, 'suv',        'SUV / 4x4',                 ROUND(1000 * 10000 / (10000 + @vat_bp)), 0, 2),
(@svc, 'utilitaire', 'Camionnette / Utilitaire',  ROUND(2000 * 10000 / (10000 + @vat_bp)), 0, 3);

-- =============================================================================
--  SERVICES CANAPÉ (domicile uniquement, un service par gabarit — pas de
--  variantes, le gabarit EST le service)
-- =============================================================================

-- --- 5. Canapé 1-3 places : 99,00 € TVAC -------------------------------------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (@cat_canape, 'Canapé 1-3', 'canape-1-3-places', 'none',
        ROUND(9900 * 10000 / (10000 + @vat_bp)), 60, 'instant', 1, 0); -- DURÉE PAR DÉFAUT
SET @svc = LAST_INSERT_ID();

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
(@svc, 'onsite', ROUND(9900 * 10000 / (10000 + @vat_bp)), 60, 1);

-- --- 6. Canapé 4-5 places : 140,00 € TVAC ------------------------------------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (@cat_canape, 'Canapé 4-5', 'canape-4-5-places', 'none',
        ROUND(14000 * 10000 / (10000 + @vat_bp)), 90, 'instant', 1, 1); -- DURÉE PAR DÉFAUT
SET @svc = LAST_INSERT_ID();

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
(@svc, 'onsite', ROUND(14000 * 10000 / (10000 + @vat_bp)), 90, 1);

-- --- 7. Canapé 6-8 places : 170,00 € TVAC ------------------------------------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (@cat_canape, 'Canapé 6-8', 'canape-6-8-places', 'none',
        ROUND(17000 * 10000 / (10000 + @vat_bp)), 120, 'instant', 1, 2); -- DURÉE PAR DÉFAUT
SET @svc = LAST_INSERT_ID();

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
(@svc, 'onsite', ROUND(17000 * 10000 / (10000 + @vat_bp)), 120, 1);

-- --- 8. Canapé + : 200,00 € TVAC (gabarit "au-delà de 6-8", à préciser dans le libellé si besoin) --
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (@cat_canape, 'Canapé +', 'canape-plus', 'none',
        ROUND(20000 * 10000 / (10000 + @vat_bp)), 150, 'instant', 1, 3); -- DURÉE PAR DÉFAUT
SET @svc = LAST_INSERT_ID();

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
(@svc, 'onsite', ROUND(20000 * 10000 / (10000 + @vat_bp)), 150, 1);

COMMIT;

-- --- Vérification rapide après exécution -------------------------------------
-- SELECT id, name, slug, base_price_cents, is_active FROM services
--  WHERE slug IN (
--    'interieur-exterieur-vehicule','remise-a-neuf-interieur-vehicule',
--    'remise-a-neuf-exterieur-vehicule','shampoing-5-sieges',
--    'canape-1-3-places','canape-4-5-places','canape-6-8-places','canape-plus'
--  );
