-- =============================================================================
-- Import en masse : services véhicule (Intérieur+Extérieur, Remise à neuf
-- Intérieur/Extérieur, Shampoing 5 sièges) et services canapé par gabarit
-- (1-3, 4-5, 6-8, +).
--
-- Version SANS variables de session (@vat_bp, @cat_vehicule, @svc…) : chaque
-- requête est autonome, avec des sous-requêtes directes. C'est volontaire —
-- certains outils d'exécution SQL (selon l'hébergeur) exécutent chaque
-- instruction dans une connexion/requête séparée et « oublient » les
-- variables @... définies par une instruction précédente, ce qui faisait
-- silencieusement échouer la version précédente du script. Cette version
-- fonctionne quel que soit l'outil utilisé (phpMyAdmin, Adminer, console
-- OVH…), même si les requêtes sont exécutées une par une.
--
-- Décisions retenues avec l'utilisateur :
--   - Prix fournis en TVAC → convertis en HT à l'insertion (colonnes *_cents
--     stockées HT). Le taux est lu dynamiquement depuis
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
--   - Les catégories sont retrouvées par slug ('vehicule'/'canape') OU par
--     nom approché (« véhicul% », « canap% ») — si aucune des deux ne
--     correspond à ton catalogue, la ligne SERVICE correspondante échouera
--     avec une erreur explicite « cannot be null » sur category_id : dans ce
--     cas, remplace directement l'ID numérique de ta catégorie dans les
--     lignes concernées (ou exécute d'abord la requête de vérification tout
--     en bas pour connaître tes vrais ID/slugs de catégories).
--   - Les durées (base_duration_min) ne sont PAS fournies dans le tableau
--     d'origine : des valeurs par défaut raisonnables sont posées ci-dessous
--     (commentaires « DURÉE PAR DÉFAUT »). Ajuste-les ensuite depuis
--     /admin/catalogue.
--   - Aucun extra (ozone, matière du canapé, etc.) n'est rattaché
--     automatiquement à ces nouveaux services.
--   - Script NON idempotent : ne pas l'exécuter deux fois (les slugs sont
--     UNIQUE, une deuxième exécution échouera proprement sur la contrainte).
-- =============================================================================

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
VALUES (
  (SELECT id FROM service_categories WHERE slug = 'vehicule' OR name LIKE '%véhicul%' OR name LIKE '%vehicul%' ORDER BY (slug = 'vehicule') DESC LIMIT 1),
  'Intérieur + Extérieur', 'interieur-exterieur-vehicule', 'gabarit',
  ROUND(9500 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))),
  150, 'instant', 1, 0 -- DURÉE PAR DÉFAUT
);

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
((SELECT id FROM services WHERE slug = 'interieur-exterieur-vehicule'), 'onsite',
 ROUND(9500 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 150, 1),
((SELECT id FROM services WHERE slug = 'interieur-exterieur-vehicule'), 'workshop',
 ROUND(8000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 150, 1);

INSERT INTO service_variants (service_id, code, label, price_delta_cents, is_default, sort_order) VALUES
((SELECT id FROM services WHERE slug = 'interieur-exterieur-vehicule'), 'citadine', 'Citadine',
 ROUND(0 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 1, 0),
((SELECT id FROM services WHERE slug = 'interieur-exterieur-vehicule'), 'berline', 'Berline',
 ROUND(1000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 0, 1),
((SELECT id FROM services WHERE slug = 'interieur-exterieur-vehicule'), 'suv', 'SUV / 4x4',
 ROUND(1500 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 0, 2),
((SELECT id FROM services WHERE slug = 'interieur-exterieur-vehicule'), 'utilitaire', 'Camionnette / Utilitaire',
 ROUND(2500 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 0, 3);

-- --- 2. Remise à neuf Intérieur : domicile 180 € / atelier 155 € TVAC --------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (
  (SELECT id FROM service_categories WHERE slug = 'vehicule' OR name LIKE '%véhicul%' OR name LIKE '%vehicul%' ORDER BY (slug = 'vehicule') DESC LIMIT 1),
  'Remise à neuf Intérieur', 'remise-a-neuf-interieur-vehicule', 'gabarit',
  ROUND(18000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))),
  240, 'instant', 1, 1 -- DURÉE PAR DÉFAUT
);

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
((SELECT id FROM services WHERE slug = 'remise-a-neuf-interieur-vehicule'), 'onsite',
 ROUND(18000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 240, 1),
((SELECT id FROM services WHERE slug = 'remise-a-neuf-interieur-vehicule'), 'workshop',
 ROUND(15500 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 240, 1);

INSERT INTO service_variants (service_id, code, label, price_delta_cents, is_default, sort_order) VALUES
((SELECT id FROM services WHERE slug = 'remise-a-neuf-interieur-vehicule'), 'citadine', 'Citadine',
 ROUND(0 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 1, 0),
((SELECT id FROM services WHERE slug = 'remise-a-neuf-interieur-vehicule'), 'berline', 'Berline',
 ROUND(1000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 0, 1),
((SELECT id FROM services WHERE slug = 'remise-a-neuf-interieur-vehicule'), 'suv', 'SUV / 4x4',
 ROUND(1500 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 0, 2),
((SELECT id FROM services WHERE slug = 'remise-a-neuf-interieur-vehicule'), 'utilitaire', 'Camionnette / Utilitaire',
 ROUND(2500 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 0, 3);

-- --- 3. Remise à neuf Extérieur : domicile 240 € / atelier 215 € TVAC --------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (
  (SELECT id FROM service_categories WHERE slug = 'vehicule' OR name LIKE '%véhicul%' OR name LIKE '%vehicul%' ORDER BY (slug = 'vehicule') DESC LIMIT 1),
  'Remise à neuf Extérieur', 'remise-a-neuf-exterieur-vehicule', 'gabarit',
  ROUND(24000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))),
  240, 'instant', 1, 2 -- DURÉE PAR DÉFAUT
);

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
((SELECT id FROM services WHERE slug = 'remise-a-neuf-exterieur-vehicule'), 'onsite',
 ROUND(24000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 240, 1),
((SELECT id FROM services WHERE slug = 'remise-a-neuf-exterieur-vehicule'), 'workshop',
 ROUND(21500 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 240, 1);

INSERT INTO service_variants (service_id, code, label, price_delta_cents, is_default, sort_order) VALUES
((SELECT id FROM services WHERE slug = 'remise-a-neuf-exterieur-vehicule'), 'citadine', 'Citadine',
 ROUND(0 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 1, 0),
((SELECT id FROM services WHERE slug = 'remise-a-neuf-exterieur-vehicule'), 'berline', 'Berline',
 ROUND(1000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 0, 1),
((SELECT id FROM services WHERE slug = 'remise-a-neuf-exterieur-vehicule'), 'suv', 'SUV / 4x4',
 ROUND(1500 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 0, 2),
((SELECT id FROM services WHERE slug = 'remise-a-neuf-exterieur-vehicule'), 'utilitaire', 'Camionnette / Utilitaire',
 ROUND(4000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 0, 3);

-- --- 4. Shampoing 5 sièges : domicile 80 € / atelier 65 € TVAC ---------------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (
  (SELECT id FROM service_categories WHERE slug = 'vehicule' OR name LIKE '%véhicul%' OR name LIKE '%vehicul%' ORDER BY (slug = 'vehicule') DESC LIMIT 1),
  'Shampoing 5 sièges', 'shampoing-5-sieges', 'gabarit',
  ROUND(8000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))),
  120, 'instant', 1, 3 -- DURÉE PAR DÉFAUT
);

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
((SELECT id FROM services WHERE slug = 'shampoing-5-sieges'), 'onsite',
 ROUND(8000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 120, 1),
((SELECT id FROM services WHERE slug = 'shampoing-5-sieges'), 'workshop',
 ROUND(6500 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 120, 1);

INSERT INTO service_variants (service_id, code, label, price_delta_cents, is_default, sort_order) VALUES
((SELECT id FROM services WHERE slug = 'shampoing-5-sieges'), 'citadine', 'Citadine',
 ROUND(0 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 1, 0),
((SELECT id FROM services WHERE slug = 'shampoing-5-sieges'), 'berline', 'Berline',
 ROUND(0 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 0, 1),
((SELECT id FROM services WHERE slug = 'shampoing-5-sieges'), 'suv', 'SUV / 4x4',
 ROUND(1000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 0, 2),
((SELECT id FROM services WHERE slug = 'shampoing-5-sieges'), 'utilitaire', 'Camionnette / Utilitaire',
 ROUND(2000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 0, 3);

-- =============================================================================
--  SERVICES CANAPÉ (domicile uniquement, un service par gabarit)
-- =============================================================================

-- --- 5. Canapé 1-3 places : 99,00 € TVAC -------------------------------------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (
  (SELECT id FROM service_categories WHERE slug = 'canape' OR name LIKE '%anapé%' OR name LIKE '%anape%' ORDER BY (slug = 'canape') DESC LIMIT 1),
  'Canapé 1-3', 'canape-1-3-places', 'none',
  ROUND(9900 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))),
  60, 'instant', 1, 0 -- DURÉE PAR DÉFAUT
);

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
((SELECT id FROM services WHERE slug = 'canape-1-3-places'), 'onsite',
 ROUND(9900 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 60, 1);

-- --- 6. Canapé 4-5 places : 140,00 € TVAC ------------------------------------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (
  (SELECT id FROM service_categories WHERE slug = 'canape' OR name LIKE '%anapé%' OR name LIKE '%anape%' ORDER BY (slug = 'canape') DESC LIMIT 1),
  'Canapé 4-5', 'canape-4-5-places', 'none',
  ROUND(14000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))),
  90, 'instant', 1, 1 -- DURÉE PAR DÉFAUT
);

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
((SELECT id FROM services WHERE slug = 'canape-4-5-places'), 'onsite',
 ROUND(14000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 90, 1);

-- --- 7. Canapé 6-8 places : 170,00 € TVAC ------------------------------------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (
  (SELECT id FROM service_categories WHERE slug = 'canape' OR name LIKE '%anapé%' OR name LIKE '%anape%' ORDER BY (slug = 'canape') DESC LIMIT 1),
  'Canapé 6-8', 'canape-6-8-places', 'none',
  ROUND(17000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))),
  120, 'instant', 1, 2 -- DURÉE PAR DÉFAUT
);

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
((SELECT id FROM services WHERE slug = 'canape-6-8-places'), 'onsite',
 ROUND(17000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 120, 1);

-- --- 8. Canapé + : 200,00 € TVAC ----------------------------------------------
INSERT INTO services (category_id, name, slug, variant_type, base_price_cents, base_duration_min, booking_mode, is_active, sort_order)
VALUES (
  (SELECT id FROM service_categories WHERE slug = 'canape' OR name LIKE '%anapé%' OR name LIKE '%anape%' ORDER BY (slug = 'canape') DESC LIMIT 1),
  'Canapé +', 'canape-plus', 'none',
  ROUND(20000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))),
  150, 'instant', 1, 3 -- DURÉE PAR DÉFAUT
);

INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, is_active) VALUES
((SELECT id FROM services WHERE slug = 'canape-plus'), 'onsite',
 ROUND(20000 * 10000 / (10000 + (SELECT COALESCE(CAST(`value` AS UNSIGNED), 2100) FROM settings WHERE `key` = 'finance.vat_rate_bp'))), 150, 1);

-- =============================================================================
--  Vérification après exécution — décommente et lance pour contrôler
-- =============================================================================
-- SELECT id, name, slug, category_id, base_price_cents, is_active FROM services
--  WHERE slug IN (
--    'interieur-exterieur-vehicule','remise-a-neuf-interieur-vehicule',
--    'remise-a-neuf-exterieur-vehicule','shampoing-5-sieges',
--    'canape-1-3-places','canape-4-5-places','canape-6-8-places','canape-plus'
--  );
