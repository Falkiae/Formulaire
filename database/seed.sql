-- =============================================================================
--  KEEPNEW BOOKING — Jeu de données de démonstration (seed)
--
--  Contenu réaliste calé sur l'activité Keepnew :
--    - 1 atelier actif (Alleur, province de Liège) + 2 postes de travail
--    - 5 techniciens itinérants avec compétences et zones différenciées
--    - Catalogue : Véhicule (intérieur / extérieur / complet), Canapé, Matelas
--    - Extras mutualisés réutilisés par plusieurs services
--    - Modes d'exécution onsite / workshop avec prix et durées propres
--    - Zones province de Liège + extension Namur/Limbourg avec supplément
--    - Réglages métier, formulaire d'intake, notifications, CGV
--
--  Montants en CENTIMES d'euro. Durées en MINUTES. Dates en UTC.
--  À charger APRÈS database/schema.sql.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
--  Réglages métier (jamais de valeurs « en dur » dans le code)
-- -----------------------------------------------------------------------------
INSERT INTO settings (`key`, `value`, value_type, `group`, label, is_secret) VALUES
('company.name',                 'Keepnew SRL',           'string',  'company',      'Raison sociale', 0),
('company.vat',                  'BE1009875116',          'string',  'company',      'Numéro de TVA', 0),
('company.phone',                '+32 4 000 00 00',       'string',  'company',      'Téléphone public', 0),
('company.email',                'hello@keepnew.be',      'string',  'company',      'Email public', 0),
('company.review_url',           '',                      'string',  'general',      'Lien public de dépôt d''avis ({{review.url}})', 0),
('display.timezone',             'Europe/Brussels',       'string',  'general',      'Fuseau d''affichage', 0),
('finance.vat_rate_bp',          '2100',                  'int',     'finance',      'Taux TVA (points de base, 2100 = 21%)', 0),
('finance.simplified_invoice_max_cents', '25000',         'int',     'finance',      'Seuil facture simplifiée TVAC (centimes)', 0),
('geo.provider',                 'ors',                   'string',  'integrations', 'Fournisseur cartographie par défaut', 0),
('geo.fallback',                 'postal_matrix',         'string',  'integrations', 'Repli si API indisponible', 0),
('geo.cache_ttl_days',           '30',                    'int',     'integrations', 'TTL cache trajets (jours)', 0),
('geo.ors_api_key',              '',                      'string',  'integrations', 'Clé API OpenRouteService', 1),
('geo.google_maps_api_key',      '',                      'string',  'integrations', 'Clé API Google Maps', 1),
('booking.default_mode',         'instant',               'string',  'booking',      'Mode de réservation par défaut', 0),
('booking.hold_minutes',         '10',                    'int',     'booking',      'Durée de blocage temporaire d''un créneau (min)', 0),
('booking.auto_advance',         '1',                     'bool',    'booking',      'Avancement automatique du tunnel mobile', 0),
('cart.expiry_days',             '7',                     'int',     'booking',      'Expiration du panier (jours)', 0),
('availability.slot_step_min',   '30',                    'int',     'availability', 'Pas de génération de créneaux (min)', 0),
('availability.min_lead_hours',  '24',                    'int',     'availability', 'Délai minimum de réservation (h)', 0),
('availability.horizon_days',    '90',                    'int',     'availability', 'Horizon maximum de réservation (j)', 0),
('availability.max_job_duration_min', '360',              'int',     'availability', 'Durée max d''un job avant fractionnement (min)', 0),
('availability.setup_buffer_min','15',                    'int',     'availability', 'Buffer setup/rangement par job (min)', 0),
('discount.multi_item_percent_bp','1000',                 'int',     'pricing',      'Remise multi-prestations (points de base, 1000 = 10%)', 0),
('discount.multi_item_min_items','2',                     'int',     'pricing',      'Nb de prestations déclenchant la remise cumul', 0),
('mobility.rate_cents_per_km',   '42',                    'int',     'compliance',   'Indemnité mobilité CP 121 (centimes/km) — barème paramétrable', 0),
('badge.most_requested_min_volume','20',                  'int',     'ux',           'Volume minimal 90j pour afficher « Le plus demandé »', 0);

-- -----------------------------------------------------------------------------
--  Compétences (skills)
-- -----------------------------------------------------------------------------
INSERT INTO skills (id, code, label) VALUES
(1, 'auto_interior', 'Nettoyage intérieur véhicule'),
(2, 'auto_exterior', 'Nettoyage extérieur véhicule'),
(3, 'sofa',          'Nettoyage canapé / textile'),
(4, 'mattress',      'Nettoyage matelas'),
(5, 'ceramic',       'Traitement céramique'),
(6, 'ozone',         'Désinfection ozone');

-- -----------------------------------------------------------------------------
--  Utilisateurs back-office & app technicien.
--  Mot de passe de démonstration commun : « keepnew-demo » (hash Argon2id).
--  À CHANGER impérativement en production, à la première connexion.
-- -----------------------------------------------------------------------------
INSERT INTO users (id, email, password_hash, first_name, last_name, phone, role, is_active) VALUES
(1, 'antoine@keepnew.be', '$argon2id$v=19$m=65536,t=4,p=1$UW9raXVSbk5vQlZPSnkySg$M/1YTiF96nCbmOhPcKre3xIhNhuQzwxS7LRCGoiE/J4', 'Antoine', 'Keepnew', '+32 470 00 00 01', 'admin', 1),
(2, 'dispatch@keepnew.be', '$argon2id$v=19$m=65536,t=4,p=1$UW9raXVSbk5vQlZPSnkySg$M/1YTiF96nCbmOhPcKre3xIhNhuQzwxS7LRCGoiE/J4', 'Dispatch', 'Keepnew', '+32 470 00 00 02', 'dispatcher', 1),
(3, 'compta@keepnew.be',  '$argon2id$v=19$m=65536,t=4,p=1$UW9raXVSbk5vQlZPSnkySg$M/1YTiF96nCbmOhPcKre3xIhNhuQzwxS7LRCGoiE/J4', 'Compta', 'Keepnew', NULL, 'accountant', 1),
(4, 'tech.karim@keepnew.be', '$argon2id$v=19$m=65536,t=4,p=1$UW9raXVSbk5vQlZPSnkySg$M/1YTiF96nCbmOhPcKre3xIhNhuQzwxS7LRCGoiE/J4', 'Karim', 'B.', '+32 471 00 00 04', 'technician', 1),
(5, 'tech.lucas@keepnew.be', '$argon2id$v=19$m=65536,t=4,p=1$UW9raXVSbk5vQlZPSnkySg$M/1YTiF96nCbmOhPcKre3xIhNhuQzwxS7LRCGoiE/J4', 'Lucas', 'D.', '+32 471 00 00 05', 'technician', 1),
(6, 'tech.sofia@keepnew.be', '$argon2id$v=19$m=65536,t=4,p=1$UW9raXVSbk5vQlZPSnkySg$M/1YTiF96nCbmOhPcKre3xIhNhuQzwxS7LRCGoiE/J4', 'Sofia', 'M.', '+32 471 00 00 06', 'technician', 1),
(7, 'tech.mehdi@keepnew.be', '$argon2id$v=19$m=65536,t=4,p=1$UW9raXVSbk5vQlZPSnkySg$M/1YTiF96nCbmOhPcKre3xIhNhuQzwxS7LRCGoiE/J4', 'Mehdi', 'R.', '+32 471 00 00 07', 'technician', 1),
(8, 'tech.elena@keepnew.be', '$argon2id$v=19$m=65536,t=4,p=1$UW9raXVSbk5vQlZPSnkySg$M/1YTiF96nCbmOhPcKre3xIhNhuQzwxS7LRCGoiE/J4', 'Elena', 'V.', '+32 471 00 00 08', 'technician', 1);

INSERT INTO permissions (id, code, description) VALUES
(1, 'catalog.edit',      'Éditer le catalogue (catégories, services, extras)'),
(2, 'dispatch.reassign', 'Réassigner des jobs dans le dispatch'),
(3, 'invoices.manage',   'Gérer les factures et la comptabilité'),
(4, 'settings.manage',   'Modifier les réglages'),
(5, 'customers.export',  'Exporter les données clients (RGPD)');

INSERT INTO user_permissions (user_id, permission_id) VALUES
(1,1),(1,2),(1,3),(1,4),(1,5),
(2,2),
(3,3);

-- -----------------------------------------------------------------------------
--  Atelier (location) + postes de travail
-- -----------------------------------------------------------------------------
INSERT INTO locations (id, name, slug, address_line, postal_code, city, country, lat, lng, phone, opening_hours, is_active, sort_order) VALUES
(1, 'Atelier Keepnew Alleur', 'atelier-alleur', 'Rue de l''Atelier 12', '4432', 'Alleur', 'BE', 50.6650000, 5.5330000, '+32 4 000 00 10',
 '{"1":[["08:00","18:00"]],"2":[["08:00","18:00"]],"3":[["08:00","18:00"]],"4":[["08:00","18:00"]],"5":[["08:00","18:00"]],"6":[["09:00","13:00"]],"0":[]}',
 1, 0);

INSERT INTO workshop_bays (id, location_id, name, accepts_json, is_active, sort_order) VALUES
(1, 1, 'Poste 1 — Mobilier', '["sofa","mattress"]', 1, 0),
(2, 1, 'Poste 2 — Polyvalent', NULL, 1, 1);

-- -----------------------------------------------------------------------------
--  Catégories (arborescence)
-- -----------------------------------------------------------------------------
INSERT INTO service_categories (id, parent_id, name, slug, description, icon, sort_order, is_visible) VALUES
(1, NULL, 'Véhicule', 'vehicule', 'Nettoyage intérieur et extérieur de votre véhicule.', 'car', 0, 1),
(2, NULL, 'Mobilier', 'mobilier', 'Canapés et matelas nettoyés en profondeur.', 'sofa', 1, 1),
(3, 2,    'Canapé',   'canape',   'Nettoyage de canapé, tous tissus.', 'sofa', 0, 1),
(4, 2,    'Matelas',  'matelas',  'Nettoyage et assainissement de matelas.', 'bed', 1, 1);

-- -----------------------------------------------------------------------------
--  Services
--  variant_type : gabarit (véhicule) / places (canapé) / dimension (matelas)
-- -----------------------------------------------------------------------------
INSERT INTO services (id, category_id, name, slug, short_description, variant_type, base_price_cents, base_duration_min, booking_mode, hybrid_max_price_cents, hybrid_max_duration_min, before_after_path, is_active, sort_order) VALUES
(1, 1, 'Nettoyage intérieur véhicule', 'nettoyage-interieur-vehicule', 'Aspiration, plastiques, vitres, sièges.', 'gabarit', 6900, 90, 'instant', NULL, NULL, '/img/ba/interieur.avif', 1, 0),
(2, 1, 'Nettoyage extérieur véhicule', 'nettoyage-exterieur-vehicule', 'Lavage carrosserie, jantes, séchage.', 'gabarit', 4900, 60, 'instant', NULL, NULL, '/img/ba/exterieur.avif', 1, 1),
(3, 1, 'Nettoyage complet véhicule',   'nettoyage-complet-vehicule',   'Intérieur + extérieur, du sol au plafond.', 'gabarit', 11900, 180, 'hybrid', 19900, 210, '/img/ba/complet.avif', 1, 2),
(4, 3, 'Nettoyage canapé',             'nettoyage-canape',             'Injection-extraction en profondeur.', 'places', 7900, 75, 'instant', NULL, NULL, '/img/ba/canape.avif', 1, 0),
(5, 4, 'Nettoyage matelas',            'nettoyage-matelas',            'Assainissement et détachage.', 'dimension', 5900, 45, 'instant', NULL, NULL, '/img/ba/matelas.avif', 1, 0);

-- Compétences requises par service
INSERT INTO service_skills (service_id, skill_id) VALUES
(1,1),
(2,2),
(3,1),(3,2),
(4,3),
(5,4);

-- -----------------------------------------------------------------------------
--  Modes d'exécution (onsite / workshop) avec prix & durées propres
--  Véhicules : essentiellement à domicile. Mobilier : domicile + atelier
--  (l'atelier est souvent moins cher — argument commercial).
-- -----------------------------------------------------------------------------
INSERT INTO service_delivery_modes (service_id, mode, price_cents, active_duration_min, occupancy_duration_min, travel_surcharge_cents, is_active) VALUES
-- Intérieur véhicule : domicile uniquement
(1, 'onsite',   6900, 90,  NULL, 0, 1),
-- Extérieur véhicule : domicile uniquement
(2, 'onsite',   4900, 60,  NULL, 0, 1),
-- Complet véhicule : domicile uniquement
(3, 'onsite',  11900, 180, NULL, 0, 1),
-- Canapé : domicile ET atelier (atelier 10€ moins cher, immobilisation séchage 120 min)
(4, 'onsite',   7900, 75,  NULL, 0, 1),
(4, 'workshop', 6900, 75,  195,  0, 1),
-- Matelas : domicile ET atelier (atelier 8€ moins cher, séchage 90 min)
(5, 'onsite',   5900, 45,  NULL, 0, 1),
(5, 'workshop', 5100, 45,  135,  0, 1);

-- -----------------------------------------------------------------------------
--  Variantes
-- -----------------------------------------------------------------------------
-- Gabarits véhicule (appliqués aux services 1,2,3). Deltas de prix/durée.
INSERT INTO service_variants (id, service_id, code, label, price_delta_cents, duration_delta_min, is_default, sort_order) VALUES
-- Intérieur (service 1)
(1, 1, 'citadine',   'Citadine',            0,     0,  1, 0),
(2, 1, 'berline',    'Berline',          1000,    10,  0, 1),
(3, 1, 'break',      'Break',            1500,    15,  0, 2),
(4, 1, 'suv',        'SUV / 4x4',        2000,    20,  0, 3),
(5, 1, 'monospace7', 'Monospace 7 places',2500,   30,  0, 4),
(6, 1, 'utilitaire', 'Utilitaire',       3000,    30,  0, 5),
-- Extérieur (service 2)
(7, 2, 'citadine',   'Citadine',            0,     0,  1, 0),
(8, 2, 'berline',    'Berline',           700,     5,  0, 1),
(9, 2, 'break',      'Break',            1000,    10,  0, 2),
(10,2, 'suv',        'SUV / 4x4',        1500,    15,  0, 3),
(11,2, 'monospace7', 'Monospace 7 places',1800,   15,  0, 4),
(12,2, 'utilitaire', 'Utilitaire',       2200,    20,  0, 5),
-- Complet (service 3)
(13,3, 'citadine',   'Citadine',            0,     0,  1, 0),
(14,3, 'berline',    'Berline',          1500,    15,  0, 1),
(15,3, 'break',      'Break',            2200,    25,  0, 2),
(16,3, 'suv',        'SUV / 4x4',        3200,    35,  0, 3),
(17,3, 'monospace7', 'Monospace 7 places',4000,   45,  0, 4),
(18,3, 'utilitaire', 'Utilitaire',       5000,    50,  0, 5),
-- Canapé : nombre de places (service 4)
(19,4, 'places_1', '1 place',            -2000,  -20,  0, 0),
(20,4, 'places_2', '2 places',           -1000,  -10,  0, 1),
(21,4, 'places_3', '3 places',               0,    0,  1, 2),
(22,4, 'places_4', '4 places',            1500,   15,  0, 3),
(23,4, 'places_5', '5 places',            3000,   30,  0, 4),
(24,4, 'places_6', '6 places et +',       4500,   45,  0, 5),
-- Matelas : dimension (service 5)
(25,5, 'dim_90',  '90 cm (1 personne)',  -1000,  -10,  0, 0),
(26,5, 'dim_140', '140 cm',                  0,    0,  0, 1),
(27,5, 'dim_160', '160 cm (queen)',        800,   10,  1, 2),
(28,5, 'dim_180', '180 cm (king)',        1500,   15,  0, 3);

-- -----------------------------------------------------------------------------
--  Extras — catalogue MUTUALISÉ (créés une fois, rattachés à N services)
-- -----------------------------------------------------------------------------
INSERT INTO extras (id, code, label, description, default_price_cents, default_duration_min, is_active) VALUES
(1, 'ozone',       'Désinfection ozone',      'Traitement anti-odeurs et bactéries à l''ozone.', 2500, 20, 1),
(2, 'ceramic',     'Traitement céramique',    'Protection hydrophobe longue durée (carrosserie).', 6900, 45, 1),
(3, 'seat_shampoo','Shampoing sièges',        'Injection-extraction des sièges tissu.', 3900, 30, 1),
(4, 'headlights',  'Rénovation phares',       'Polissage des optiques ternies.', 3500, 25, 1),
(5, 'pet_hair',    'Poils d''animaux',        'Retrait renforcé des poils incrustés.', 1900, 20, 1),
(6, 'deep_stains', 'Taches profondes',        'Traitement des taches tenaces.', 2200, 20, 1),
(7, 'anti_stain',  'Traitement anti-taches',  'Protection préventive du textile.', 2900, 15, 1),
(8, 'anti_mite',   'Traitement anti-acariens','Assainissement en profondeur.', 1900, 15, 1),
(9, 'double_face', 'Double face',             'Nettoyage des deux faces du matelas.', 1500, 15, 1),
-- Matière du canapé (groupe exclusif « radio »)
(10,'mat_tissu',   'Tissu',                   'Canapé en tissu.', 0, 0, 1),
(11,'mat_cuir',    'Cuir',                    'Canapé en cuir (nourrissage inclus).', 2500, 20, 1),
(12,'mat_alcantara','Alcantara',              'Canapé en alcantara / microfibre.', 1800, 15, 1);

-- Rattachement service <-> extra (avec surcharges et groupes exclusifs)
INSERT INTO service_extras (service_id, extra_id, price_cents, duration_min, selection_type, exclusive_group, sort_order) VALUES
-- Intérieur véhicule
(1, 1, NULL, NULL, 'checkbox', NULL, 0),
(1, 3, NULL, NULL, 'checkbox', NULL, 1),
(1, 5, NULL, NULL, 'checkbox', NULL, 2),
(1, 6, NULL, NULL, 'checkbox', NULL, 3),
-- Extérieur véhicule
(2, 2, NULL, NULL, 'checkbox', NULL, 0),
(2, 4, NULL, NULL, 'checkbox', NULL, 1),
-- Complet véhicule (hérite des deux)
(3, 1, NULL, NULL, 'checkbox', NULL, 0),
(3, 2, NULL, NULL, 'checkbox', NULL, 1),
(3, 4, NULL, NULL, 'checkbox', NULL, 2),
(3, 5, NULL, NULL, 'checkbox', NULL, 3),
(3, 6, NULL, NULL, 'checkbox', NULL, 4),
-- Canapé : matière (radio exclusif) + options
(4, 10, NULL, NULL, 'radio', 'matiere', 0),
(4, 11, NULL, NULL, 'radio', 'matiere', 1),
(4, 12, NULL, NULL, 'radio', 'matiere', 2),
(4, 1,  NULL, NULL, 'checkbox', NULL, 3),
(4, 7,  NULL, NULL, 'checkbox', NULL, 4),
(4, 6,  NULL, NULL, 'checkbox', NULL, 5),
-- Matelas : options
(5, 9,  NULL, NULL, 'checkbox', NULL, 0),
(5, 8,  NULL, NULL, 'checkbox', NULL, 1),
(5, 1,  NULL, NULL, 'checkbox', NULL, 2),
(5, 6,  NULL, NULL, 'checkbox', NULL, 3);

-- -----------------------------------------------------------------------------
--  Règles de tarification & de durée
-- -----------------------------------------------------------------------------
INSERT INTO pricing_rules (id, code, label, rule_type, calc_type, calc_value, conditions, priority, is_active) VALUES
(1, 'multi_item_10', 'Remise multi-prestations −10 % dès la 2e à la même adresse', 'multi_item_discount', 'percent', 1000, '{"min_items":2,"same_address":true,"applies_from_item":2}', 100, 1);

INSERT INTO duration_rules (id, code, label, rule_type, calc_type, calc_value, conditions, priority, is_active) VALUES
(1, 'setup_buffer_15', 'Buffer de setup/rangement par job', 'setup_buffer', 'fixed', 15, NULL, 10, 1),
(2, 'cumul_discount_10', 'Décote de cumul −10 % sur les durées additionnelles au même endroit', 'cumul_discount', 'percent', 1000, '{"same_address":true,"applies_from_item":2}', 50, 1);

-- Coupons
INSERT INTO coupons (id, code, label, discount_type, discount_value, min_order_cents, max_redemptions, is_active) VALUES
(1, 'BIENVENUE10', 'Remise de bienvenue 10 %', 'percent', 1000, 5000, NULL, 1),
(2, 'PRINTEMPS15', 'Offre de printemps 15 €', 'fixed', 1500, 8000, 100, 1);

-- -----------------------------------------------------------------------------
--  Zones de service
-- -----------------------------------------------------------------------------
INSERT INTO service_zones (id, name, zone_type, center_lat, center_lng, radius_km, is_active, priority) VALUES
(1, 'Liège — rayon 25 km', 'radius', 50.6326000, 5.5797000, 25.00, 1, 10),
(2, 'Province de Liège — codes postaux', 'postal_codes', NULL, NULL, NULL, 1, 20),
(3, 'Extension Namur / Limbourg', 'postal_codes', NULL, NULL, NULL, 1, 30);

INSERT INTO zone_postal_codes (zone_id, postal_code) VALUES
(2,'4000'),(2,'4020'),(2,'4030'),(2,'4031'),(2,'4032'),(2,'4040'),(2,'4100'),(2,'4300'),
(2,'4430'),(2,'4432'),(2,'4500'),(2,'4600'),(2,'4800'),(2,'4801'),(2,'4820'),(2,'4900'),
(3,'5000'),(3,'5100'),(3,'3500'),(3,'3800');

-- Extension = supplément déplacement de 15 €
INSERT INTO zone_pricing_modifiers (zone_id, modifier_type, calc_type, calc_value, label) VALUES
(3, 'surcharge', 'fixed', 1500, 'Supplément déplacement hors province de Liège');

-- -----------------------------------------------------------------------------
--  Techniciens (5) — compétences, zones, points de départ, disponibilités
-- -----------------------------------------------------------------------------
INSERT INTO technicians (id, user_id, first_name, last_name, phone, email, home_lat, home_lng, home_postal, max_jobs_per_day, is_active) VALUES
(1, 4, 'Karim', 'B.', '+32 471 00 00 04', 'tech.karim@keepnew.be', 50.6420000, 5.5710000, '4000', 6, 1),
(2, 5, 'Lucas', 'D.', '+32 471 00 00 05', 'tech.lucas@keepnew.be', 50.6100000, 5.5010000, '4100', 6, 1),
(3, 6, 'Sofia', 'M.', '+32 471 00 00 06', 'tech.sofia@keepnew.be', 50.6650000, 5.5330000, '4432', 5, 1),
(4, 7, 'Mehdi', 'R.', '+32 471 00 00 07', 'tech.mehdi@keepnew.be', 50.4230000, 5.2380000, '4500', 6, 1),
(5, 8, 'Elena', 'V.', '+32 471 00 00 08', 'tech.elena@keepnew.be', 50.5900000, 5.8600000, '4800', 5, 1);

-- Compétences par technicien (différenciées)
INSERT INTO technician_skills (technician_id, skill_id) VALUES
(1,1),(1,2),(1,5),            -- Karim : véhicule complet + céramique
(2,1),(2,2),                  -- Lucas : véhicule
(3,3),(3,4),(3,6),            -- Sofia : mobilier + ozone (aussi à l'atelier)
(4,1),(4,3),(4,4),            -- Mehdi : polyvalent (Huy/Condroz)
(5,2),(5,3),(5,4);            -- Elena : extérieur + mobilier (Verviers)

-- Zones assignées
INSERT INTO technician_zones (technician_id, zone_id) VALUES
(1,1),(1,2),
(2,1),(2,2),
(3,1),(3,2),
(4,2),(4,3),
(5,2),(5,3);

-- Disponibilités récurrentes (heures locales Europe/Brussels). 1=lun … 5=ven, 6=sam
INSERT INTO technician_availability (technician_id, weekday, start_time, end_time, location_id) VALUES
(1,1,'08:00','17:00',NULL),(1,2,'08:00','17:00',NULL),(1,3,'08:00','17:00',NULL),(1,4,'08:00','17:00',NULL),(1,5,'08:00','16:00',NULL),
(2,1,'08:30','17:30',NULL),(2,2,'08:30','17:30',NULL),(2,3,'08:30','17:30',NULL),(2,4,'08:30','17:30',NULL),(2,5,'08:30','16:00',NULL),
-- Sofia travaille surtout à l'atelier (location_id=1)
(3,1,'08:00','18:00',1),(3,2,'08:00','18:00',1),(3,3,'08:00','18:00',1),(3,4,'08:00','18:00',1),(3,5,'08:00','17:00',1),(3,6,'09:00','13:00',1),
(4,1,'08:00','17:00',NULL),(4,2,'08:00','17:00',NULL),(4,3,'08:00','17:00',NULL),(4,4,'08:00','17:00',NULL),(4,5,'08:00','16:00',NULL),
(5,1,'08:00','17:00',NULL),(5,2,'08:00','17:00',NULL),(5,3,'08:00','17:00',NULL),(5,4,'08:00','17:00',NULL),(5,5,'08:00','16:00',NULL);

-- Un congé d'exemple (Lucas absent une journée)
INSERT INTO technician_time_off (technician_id, starts_at, ends_at, reason) VALUES
(2, '2026-07-27 06:00:00', '2026-07-27 20:00:00', 'Congé');

-- Véhicules de service
INSERT INTO vehicles (id, name, plate, is_active) VALUES
(1, 'Fourgon 1', '1-KEE-001', 1),
(2, 'Fourgon 2', '1-KEE-002', 1),
(3, 'Fourgon 3', '1-KEE-003', 1);

-- -----------------------------------------------------------------------------
--  Matrice de trajet de repli (codes postaux) — extrait province de Liège
-- -----------------------------------------------------------------------------
INSERT INTO postal_travel_matrix (from_postal, to_postal, duration_min, distance_km) VALUES
('4000','4000',5,2.0),('4000','4432',12,7.5),('4000','4100',15,9.0),('4000','4040',12,7.0),
('4000','4800',35,28.0),('4000','4500',30,32.0),('4432','4100',20,13.0),('4432','4000',12,7.5),
('4100','4000',15,9.0),('4800','4000',35,28.0),('4500','4000',30,32.0);

-- -----------------------------------------------------------------------------
--  Formulaire dynamique — version publiée + champs d'intake
-- -----------------------------------------------------------------------------
INSERT INTO form_versions (id, version, label, is_published, published_at, created_by) VALUES
(1, 1, 'Formulaire de réservation v1', 1, '2026-07-01 00:00:00', 1);

INSERT INTO form_fields (id, version_id, field_key, label, help_text, field_type, step, sort_order, is_required, config_json) VALUES
(1, 1, 'water_access',   'Avez-vous un accès à l''eau ?',        'Utile pour certaines prestations à domicile.', 'radio',  5, 0, 1, '{"onsite_only":true}'),
(2, 1, 'power_access',   'Avez-vous un accès à l''électricité ?', NULL, 'radio',  5, 1, 1, '{"onsite_only":true}'),
(3, 1, 'parking',        'Le stationnement est-il possible devant chez vous ?', NULL, 'radio', 5, 2, 0, '{"onsite_only":true}'),
(4, 1, 'floor',          'À quel étage se situe l''intervention ?', 'Sans ascenseur, précisez-le.', 'select', 5, 3, 0, '{"onsite_only":true}'),
(5, 1, 'pets',           'Avez-vous des animaux ?',              NULL, 'radio',  5, 4, 0, NULL),
(6, 1, 'dirt_level',     'Quel est l''état de salissure ?',      'Choisissez le visuel le plus proche.', 'cards', 5, 5, 1, '{"scale":true}'),
(7, 1, 'dirt_photo',     'Ajoutez une photo (facultatif)',       'Cela nous aide à mieux préparer l''intervention.', 'photo', 5, 6, 0, NULL);

-- Options du champ « niveau de salissure » — influence la DURÉE (échelle photo)
INSERT INTO form_field_options (field_id, value, label, image_path, duration_modifier_type, duration_modifier_value, sort_order) VALUES
(6, 'light',   'Léger',   '/img/dirt/light.avif',  'percent', 0,    0),
(6, 'marked',  'Marqué',  '/img/dirt/marked.avif', 'percent', 1500, 1),
(6, 'deep',    'Profond', '/img/dirt/deep.avif',   'percent', 3000, 2);

-- Options oui/non pour les champs radio
INSERT INTO form_field_options (field_id, value, label, sort_order) VALUES
(1,'yes','Oui',0),(1,'no','Non',1),
(2,'yes','Oui',0),(2,'no','Non',1),
(3,'yes','Oui',0),(3,'no','Non',1),
(5,'yes','Oui',0),(5,'no','Non',1),
(4,'0','Rez-de-chaussée',0),(4,'1','1er étage',1),(4,'2','2e étage',2),(4,'3','3e étage ou +',3);

-- Logique conditionnelle : si animaux = oui, exiger le niveau de salissure
INSERT INTO form_conditions (version_id, source_field_id, operator, compare_value, action, target_field_id) VALUES
(1, 5, 'eq', 'yes', 'require', 6);

-- -----------------------------------------------------------------------------
--  Conditions générales (archivées avec les commandes)
-- -----------------------------------------------------------------------------
INSERT INTO terms_versions (id, version, body, published_at, is_current) VALUES
(1, '2026-01', 'Conditions générales de vente Keepnew SRL (BE 1009.875.116). Paiement après intervention. Annulation sans frais jusqu''à 24 h avant le rendez-vous.', '2026-01-01 00:00:00', 1);

-- -----------------------------------------------------------------------------
--  Notifications — événements + templates
-- -----------------------------------------------------------------------------
INSERT INTO notification_events (id, event_key, label, is_active) VALUES
(1, 'booking_created',    'Demande de réservation reçue', 1),
(2, 'booking_confirmed',  'Réservation confirmée', 1),
(3, 'reminder_48h',       'Rappel 48 h avant', 1),
(4, 'reminder_2h',        'Rappel 2 h avant', 1),
(5, 'technician_en_route','Technicien en route', 1),
(6, 'job_completed',      'Intervention terminée', 1),
(7, 'review_request',     'Demande d''avis', 1),
(8, 'cart_abandoned',     'Panier abandonné', 1),
(9, 'booking_cancelled',  'Réservation annulée', 1);

INSERT INTO notification_templates (event_id, channel, offset_minutes, subject, body, is_active) VALUES
(1, 'email', 0, 'Votre demande Keepnew {{booking.reference}}', 'Bonjour {{customer.first_name}}, nous avons bien reçu votre demande. Nous revenons vers vous très vite.', 1),
(2, 'email', 0, 'C''est confirmé — {{booking.reference}}', 'Bonjour {{customer.first_name}}, votre rendez-vous est confirmé pour le {{job.date}}. Vous payez après l''intervention. Pour consulter, modifier ou annuler votre réservation : {{booking.manage_url}}', 1),
(3, 'email', -2880, 'Rappel — rendez-vous Keepnew dans 48 h', 'Bonjour {{customer.first_name}}, petit rappel de votre rendez-vous le {{job.date}}.', 1),
(4, 'sms',   -120, NULL, 'Keepnew : votre technicien passera vers {{job.arrival_from}}. À tout à l''heure !', 1),
(5, 'sms',   0, NULL, 'Keepnew : {{technician.first_name}} vient de partir vers chez vous.', 1),
(8, 'email', 60, 'Votre devis Keepnew vous attend', 'Bonjour, on a gardé votre devis. Reprenez là où vous vous étiez arrêté : {{cart.resume_url}}', 1),
(9, 'email', 0, 'Votre rendez-vous Keepnew {{booking.reference}} est annulé', 'Bonjour {{customer.first_name}}, votre rendez-vous du {{job.date}} ({{booking.reference}}) est bien annulé. Rien ne vous sera facturé. Au plaisir de vous revoir : réservez quand vous le souhaitez sur keepnew.be.', 1),
(6, 'email', 0, 'C''est terminé — merci {{customer.first_name}} !', 'Bonjour {{customer.first_name}}, votre intervention est terminée. Nous espérons que le résultat vous plaît ! Votre facture ({{booking.reference}}, {{booking.total}}) vous parvient séparément. La moindre question, écrivez-nous : on répond vite.', 1),
(7, 'email', 0, 'Votre avis compte pour nous, {{customer.first_name}}', 'Bonjour {{customer.first_name}}, merci de nous avoir fait confiance. Si le résultat vous a plu, un mot de votre part aide énormément une petite équipe comme la nôtre : {{review.url}} — deux minutes suffisent. Et si quelque chose n''allait pas, répondez à cet e-mail : on préfère le savoir et le rattraper.', 1);

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
--  FIN DU SEED
-- =============================================================================
