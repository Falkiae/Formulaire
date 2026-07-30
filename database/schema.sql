-- =============================================================================
--  KEEPNEW BOOKING — Schéma de base de données
--  Keepnew SRL (BE 1009.875.116) — plateforme de prise de rendez-vous
--
--  Cible      : MySQL 8 / MariaDB 10.6+
--  Moteur     : InnoDB, utf8mb4_unicode_ci
--  Conventions :
--    - Tous les MONTANTS sont stockés en ENTIERS = centimes d'euro (jamais de float).
--    - Toutes les DATES/HEURES sont en UTC (type DATETIME). L'affichage se fait en
--      Europe/Brussels côté application.
--    - Toutes les DURÉES sont en MINUTES (entiers).
--    - Clés primaires : BIGINT UNSIGNED AUTO_INCREMENT.
--    - Colonnes de traçabilité : created_at / updated_at systématiques.
--
--  Phase 1 du projet : ce fichier crée la structure complète du modèle de données.
--  Le jeu de données de démonstration est dans database/seed.sql.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
--  0. RÉGLAGES GÉNÉRAUX & UTILISATEURS BACK-OFFICE
-- =============================================================================

-- Réglages clé/valeur de l'application (barèmes, seuils, intégrations…).
-- Jamais de valeurs « en dur » dans le code : indemnité de mobilité, TVA,
-- seuils instant/request, etc. vivent ici et sont éditables en back-office.
CREATE TABLE settings (
    `key`        VARCHAR(120)    NOT NULL,
    `value`      TEXT            NULL,
    value_type   ENUM('string','int','bool','json','decimal') NOT NULL DEFAULT 'string',
    `group`      VARCHAR(60)     NOT NULL DEFAULT 'general',
    label        VARCHAR(190)    NULL,
    is_secret    TINYINT(1)      NOT NULL DEFAULT 0,  -- secrets → jamais exposés côté public
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`),
    KEY idx_settings_group (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comptes du back-office et de l'app technicien.
CREATE TABLE users (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email          VARCHAR(190)    NOT NULL,
    password_hash  VARCHAR(255)    NOT NULL,             -- Argon2id
    first_name     VARCHAR(120)    NOT NULL,
    last_name      VARCHAR(120)    NOT NULL,
    phone          VARCHAR(40)     NULL,
    role           ENUM('admin','dispatcher','technician','accountant') NOT NULL,
    is_active      TINYINT(1)      NOT NULL DEFAULT 1,
    last_login_at  DATETIME        NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions granulaires (au-delà du rôle) — assignables à un compte.
CREATE TABLE permissions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(120)    NOT NULL,   -- ex. 'catalog.edit', 'dispatch.reassign'
    description VARCHAR(255)    NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_permissions (
    user_id       BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, permission_id),
    CONSTRAINT fk_userperm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_userperm_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalogue de compétences (skills) requis par service / détenus par technicien.
CREATE TABLE skills (
    id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code   VARCHAR(80)     NOT NULL,   -- ex. 'auto_interior', 'sofa_leather', 'mattress'
    label  VARCHAR(160)    NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_skills_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  1. ATELIERS (LOCATIONS) & POSTES DE TRAVAIL
--     Un seul atelier actif au lancement, mais la structure supporte le multi-site.
-- =============================================================================

CREATE TABLE locations (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(160)    NOT NULL,
    slug           VARCHAR(160)    NOT NULL,
    address_line   VARCHAR(255)    NULL,
    postal_code    VARCHAR(12)     NULL,
    city           VARCHAR(120)    NULL,
    country        CHAR(2)         NOT NULL DEFAULT 'BE',
    lat            DECIMAL(10,7)   NULL,
    lng            DECIMAL(10,7)   NULL,
    phone          VARCHAR(40)     NULL,
    -- horaires d'ouverture propres à l'atelier, JSON par jour de semaine
    -- ex. {"1":[["08:00","18:00"]], "6":[["09:00","13:00"]], "0":[]}  (0=dimanche)
    opening_hours  JSON            NULL,
    is_active      TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order     INT             NOT NULL DEFAULT 0,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_locations_slug (slug),
    KEY idx_locations_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Postes de travail (bays) : ressource limitante en mode atelier.
CREATE TABLE workshop_bays (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    location_id  BIGINT UNSIGNED NOT NULL,
    name         VARCHAR(120)    NOT NULL,   -- ex. 'Poste 1', 'Cabine ozone'
    -- restriction éventuelle : un poste peut n'accepter que certains types de service
    accepts_json JSON            NULL,       -- ex. ["sofa","mattress"] ; null = tout
    is_active    TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order   INT             NOT NULL DEFAULT 0,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bays_location (location_id),
    CONSTRAINT fk_bays_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fermetures exceptionnelles d'un atelier (jours fériés spécifiques, congés).
CREATE TABLE location_closures (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    location_id  BIGINT UNSIGNED NOT NULL,
    starts_at    DATETIME        NOT NULL,  -- UTC
    ends_at      DATETIME        NOT NULL,
    reason       VARCHAR(190)    NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_closures_location (location_id, starts_at),
    CONSTRAINT fk_closures_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  2. CATALOGUE & TARIFICATION
-- =============================================================================

-- Arborescence des catégories (Véhicule / Mobilier / …).
CREATE TABLE service_categories (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id    BIGINT UNSIGNED NULL,
    name         VARCHAR(160)    NOT NULL,
    slug         VARCHAR(180)    NOT NULL,      -- slug SEO
    description  TEXT            NULL,
    icon         VARCHAR(120)    NULL,          -- nom d'icône SVG du set
    image_path   VARCHAR(255)    NULL,
    sort_order   INT             NOT NULL DEFAULT 0,
    is_visible   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    KEY idx_categories_parent (parent_id, sort_order),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES service_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Services (rattachés à une catégorie). Prix/durée de base ; les modes de
-- livraison (onsite/workshop) portent leurs propres prix/durée (voir plus bas).
CREATE TABLE services (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id         BIGINT UNSIGNED NOT NULL,
    name                VARCHAR(190)    NOT NULL,
    slug                VARCHAR(200)    NOT NULL,
    short_description   VARCHAR(255)    NULL,
    badge_label         VARCHAR(40)     NULL,   -- mise en avant ("Plus demandée"…), affiché tel quel
    description         TEXT            NULL,
    -- 'variantless' | 'gabarit' | 'places' | 'dimension' : nature des variantes
    variant_type        ENUM('none','gabarit','places','dimension') NOT NULL DEFAULT 'none',
    base_price_cents    INT             NOT NULL DEFAULT 0,   -- prix de base (centimes)
    base_duration_min   SMALLINT UNSIGNED NOT NULL DEFAULT 60,-- durée active de base (min)
    image_path          VARCHAR(255)    NULL,
    -- photo avant/après spécifique à ce service (réassurance étape « choix du service »)
    before_after_path   VARCHAR(255)    NULL,
    booking_mode        ENUM('instant','request','hybrid') NOT NULL DEFAULT 'instant',
    -- seuils du mode hybride (au-delà → passage en 'request')
    hybrid_max_price_cents INT          NULL,
    hybrid_max_duration_min SMALLINT UNSIGNED NULL,
    is_active           TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order          INT             NOT NULL DEFAULT 0,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_services_slug (slug),
    KEY idx_services_category (category_id, sort_order),
    KEY idx_services_active (is_active),
    CONSTRAINT fk_services_category FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compétences requises par un service (pour le filtrage des techniciens).
CREATE TABLE service_skills (
    service_id BIGINT UNSIGNED NOT NULL,
    skill_id   BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (service_id, skill_id),
    CONSTRAINT fk_serviceskill_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    CONSTRAINT fk_serviceskill_skill   FOREIGN KEY (skill_id)   REFERENCES skills(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modes d'exécution d'un service : à domicile (onsite) et/ou atelier (workshop),
-- chacun avec son prix et sa durée propres. Dimension de premier niveau.
CREATE TABLE service_delivery_modes (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id          BIGINT UNSIGNED NOT NULL,
    mode                ENUM('onsite','workshop') NOT NULL,
    -- Surcharge du prix/durée de base pour ce mode. NULL = on retombe sur la base.
    price_cents         INT             NULL,
    active_duration_min SMALLINT UNSIGNED NULL,   -- travail actif du technicien
    -- Immobilisation d'un poste au-delà du travail actif (séchage) — mode atelier.
    occupancy_duration_min SMALLINT UNSIGNED NULL,
    -- Supplément déplacement propre au mode onsite (souvent 0, la zone module).
    travel_surcharge_cents INT          NOT NULL DEFAULT 0,
    is_active           TINYINT(1)      NOT NULL DEFAULT 1,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_mode (service_id, mode),
    CONSTRAINT fk_delivmode_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Variantes d'un service (gabarit véhicule / nombre de places / dimension matelas).
-- Portent un delta de prix et de durée par rapport à la base du service.
CREATE TABLE service_variants (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id          BIGINT UNSIGNED NOT NULL,
    code                VARCHAR(80)     NOT NULL,  -- ex. 'suv', 'places_3', 'dim_160'
    label               VARCHAR(160)    NOT NULL,  -- ex. 'SUV', '3 places', '160 cm'
    image_path          VARCHAR(255)    NULL,
    -- Modificateurs appliqués sur le prix/durée du service pour cette variante.
    price_delta_cents   INT             NOT NULL DEFAULT 0,
    duration_delta_min  SMALLINT        NOT NULL DEFAULT 0,
    -- Optionnel : prix/durée absolus si la variante remplace complètement la base.
    price_override_cents    INT         NULL,
    duration_override_min   SMALLINT UNSIGNED NULL,
    is_default          TINYINT(1)      NOT NULL DEFAULT 0,
    is_active           TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order          INT             NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_variant_service_code (service_id, code),
    KEY idx_variants_service (service_id, sort_order),
    CONSTRAINT fk_variants_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalogue d'extras MUTUALISÉ : créé une seule fois, réutilisé par N services.
CREATE TABLE extras (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                VARCHAR(80)     NOT NULL,   -- ex. 'ozone', 'ceramic', 'pet_hair'
    label               VARCHAR(190)    NOT NULL,
    description         VARCHAR(255)    NULL,
    icon                VARCHAR(120)    NULL,
    image_path          VARCHAR(255)    NULL,
    -- Prix/durée par défaut de l'extra (surchargeables par service, voir pivot).
    default_price_cents INT             NOT NULL DEFAULT 0,
    default_duration_min SMALLINT       NOT NULL DEFAULT 0,
    is_active           TINYINT(1)      NOT NULL DEFAULT 1,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_extras_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pivot service <-> extra : quel extra s'applique à quel service, avec surcharge
-- possible de prix/durée, exclusivité (radio) ou cumul (checkbox), et condition
-- éventuelle sur une variante précise.
CREATE TABLE service_extras (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id          BIGINT UNSIGNED NOT NULL,
    extra_id            BIGINT UNSIGNED NOT NULL,
    -- Surcharge : NULL = on retombe sur les valeurs par défaut de l'extra.
    price_cents         INT             NULL,
    duration_min        SMALLINT        NULL,
    -- 'checkbox' = cumulable, 'radio' = exclusif au sein d'un même groupe.
    selection_type      ENUM('checkbox','radio') NOT NULL DEFAULT 'checkbox',
    exclusive_group     VARCHAR(60)     NULL,   -- pour regrouper des extras 'radio'
    -- Condition : extra visible uniquement si cette variante est choisie (ou NULL).
    requires_variant_id BIGINT UNSIGNED NULL,
    is_active           TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order          INT             NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_extra (service_id, extra_id),
    KEY idx_serviceextras_service (service_id, sort_order),
    CONSTRAINT fk_serviceextra_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    CONSTRAINT fk_serviceextra_extra   FOREIGN KEY (extra_id)   REFERENCES extras(id)   ON DELETE CASCADE,
    CONSTRAINT fk_serviceextra_variant FOREIGN KEY (requires_variant_id) REFERENCES service_variants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Règles de tarification transverses (remises multi-prestations, majorations…).
-- Le moteur de prix (phase 3) les applique dans un ordre explicite (priority).
CREATE TABLE pricing_rules (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(80)     NOT NULL,
    label         VARCHAR(190)    NOT NULL,
    -- type de règle : remise cumul, majoration weekend/urgence, etc.
    rule_type     ENUM('multi_item_discount','surcharge','min_order','zone_modifier') NOT NULL,
    -- calcul : fixe (centimes), pourcentage (base 10000 = 100.00%), multiplicateur
    calc_type     ENUM('fixed','percent','multiplier') NOT NULL,
    calc_value    INT             NOT NULL,   -- centimes / points de base / x1000
    -- conditions d'application encodées en JSON (seuils, jours, min items…)
    conditions    JSON            NULL,
    priority      INT             NOT NULL DEFAULT 100,  -- ordre d'application croissant
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pricing_rules_code (code),
    KEY idx_pricing_rules_active (is_active, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Règles de durée (décote de cumul, buffers de setup/rangement…).
CREATE TABLE duration_rules (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(80)     NOT NULL,
    label         VARCHAR(190)    NOT NULL,
    rule_type     ENUM('setup_buffer','cumul_discount','mode_modifier') NOT NULL,
    calc_type     ENUM('fixed','percent','multiplier') NOT NULL,
    calc_value    INT             NOT NULL,   -- minutes / points de base / x1000
    conditions    JSON            NULL,
    priority      INT             NOT NULL DEFAULT 100,
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_duration_rules_code (code),
    KEY idx_duration_rules_active (is_active, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Codes promo / coupons.
CREATE TABLE coupons (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code               VARCHAR(60)     NOT NULL,
    label              VARCHAR(190)    NULL,
    discount_type      ENUM('fixed','percent') NOT NULL,
    discount_value     INT             NOT NULL,   -- centimes ou points de base
    min_order_cents    INT             NOT NULL DEFAULT 0,
    max_redemptions    INT             NULL,        -- NULL = illimité
    redeemed_count     INT             NOT NULL DEFAULT 0,
    per_customer_limit INT             NULL,
    starts_at          DATETIME        NULL,
    ends_at            DATETIME        NULL,
    is_active          TINYINT(1)      NOT NULL DEFAULT 1,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coupons_code (code),
    KEY idx_coupons_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historisation des prix (pour ne pas fausser les commandes passées lors d'un
-- changement de tarif). Écrit à chaque modification d'un prix de service/mode.
CREATE TABLE price_history (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type   ENUM('service','service_delivery_mode','service_variant','service_extra','extra') NOT NULL,
    entity_id     BIGINT UNSIGNED NOT NULL,
    old_price_cents INT           NULL,
    new_price_cents INT           NULL,
    old_duration_min SMALLINT     NULL,
    new_duration_min SMALLINT     NULL,
    changed_by    BIGINT UNSIGNED NULL,
    changed_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_price_history_entity (entity_type, entity_id, changed_at),
    CONSTRAINT fk_pricehist_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  3. ZONES DE SERVICE
-- =============================================================================

CREATE TABLE service_zones (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(160)    NOT NULL,
    zone_type    ENUM('polygon','radius','postal_codes') NOT NULL,
    -- radius : centre + rayon ; polygon : géométrie GeoJSON en JSON.
    center_lat   DECIMAL(10,7)   NULL,
    center_lng   DECIMAL(10,7)   NULL,
    radius_km    DECIMAL(6,2)    NULL,
    polygon_json JSON            NULL,   -- GeoJSON Polygon si zone_type='polygon'
    -- comportement hors zone stricte : refus ou supplément (voir modifiers).
    is_active    TINYINT(1)      NOT NULL DEFAULT 1,
    priority     INT             NOT NULL DEFAULT 100,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_zones_active (is_active, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE zone_postal_codes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    zone_id     BIGINT UNSIGNED NOT NULL,
    postal_code VARCHAR(12)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_zone_postal (zone_id, postal_code),
    KEY idx_zone_postal_code (postal_code),
    CONSTRAINT fk_zonepostal_zone FOREIGN KEY (zone_id) REFERENCES service_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modificateurs tarifaires liés à une zone (supplément déplacement, refus…).
CREATE TABLE zone_pricing_modifiers (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    zone_id      BIGINT UNSIGNED NOT NULL,
    modifier_type ENUM('surcharge','discount','refuse') NOT NULL,
    calc_type    ENUM('fixed','percent') NULL,
    calc_value   INT             NULL,       -- centimes ou points de base
    label        VARCHAR(190)    NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_zonemod_zone (zone_id),
    CONSTRAINT fk_zonemod_zone FOREIGN KEY (zone_id) REFERENCES service_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  4. CLIENTS & ADRESSES
-- =============================================================================

CREATE TABLE customers (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type           ENUM('b2c','b2b') NOT NULL DEFAULT 'b2c',
    first_name     VARCHAR(120)    NULL,
    last_name      VARCHAR(120)    NULL,
    company_name   VARCHAR(190)    NULL,
    vat_number     VARCHAR(20)     NULL,   -- TVA intracom (B2B / Peppol)
    email          VARCHAR(190)    NOT NULL,
    phone          VARCHAR(40)     NULL,
    -- segment marketing calculé (LTV, fréquence) ou saisi.
    segment        VARCHAR(60)     NULL,
    -- anonymisation RGPD : marque le client comme effacé sans casser la compta.
    anonymized_at  DATETIME        NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customers_email (email),
    KEY idx_customers_phone (phone),
    KEY idx_customers_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE addresses (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id   BIGINT UNSIGNED NULL,
    label         VARCHAR(120)    NULL,       -- ex. 'Domicile', 'Garage'
    street        VARCHAR(255)    NOT NULL,
    number        VARCHAR(20)     NULL,
    box           VARCHAR(20)     NULL,
    postal_code   VARCHAR(12)     NOT NULL,
    city          VARCHAR(120)    NOT NULL,
    country       CHAR(2)         NOT NULL DEFAULT 'BE',
    lat           DECIMAL(10,7)   NULL,       -- géocodage
    lng           DECIMAL(10,7)   NULL,
    -- informations d'accès (mode onsite) : étage, parking, eau, électricité…
    access_notes  TEXT            NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_addresses_customer (customer_id),
    KEY idx_addresses_postal (postal_code),
    CONSTRAINT fk_addresses_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_notes (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id  BIGINT UNSIGNED NOT NULL,
    author_id    BIGINT UNSIGNED NULL,
    body         TEXT            NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_notes_customer (customer_id),
    CONSTRAINT fk_customernotes_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_customernotes_author   FOREIGN KEY (author_id)   REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  5. RESSOURCES : TECHNICIENS & VÉHICULES
-- =============================================================================

CREATE TABLE technicians (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NULL,       -- compte de connexion (app technicien)
    first_name    VARCHAR(120)    NOT NULL,
    last_name     VARCHAR(120)    NOT NULL,
    phone         VARCHAR(40)     NULL,
    email         VARCHAR(190)    NULL,
    -- point de départ par défaut (domicile ou dépôt) pour le calcul de trajet.
    home_lat      DECIMAL(10,7)   NULL,
    home_lng      DECIMAL(10,7)   NULL,
    home_postal   VARCHAR(12)     NULL,
    max_jobs_per_day SMALLINT     NOT NULL DEFAULT 6,
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Un compte ne pilote qu'une fiche (l'app terrain n'en résout qu'une).
    -- Plusieurs NULL restent permis : une fiche sans compte de connexion est
    -- valide, elle n'a simplement pas accès à /tech.
    UNIQUE KEY uq_technicians_user (user_id),
    KEY idx_technicians_active (is_active),
    CONSTRAINT fk_technicians_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE technician_skills (
    technician_id BIGINT UNSIGNED NOT NULL,
    skill_id      BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (technician_id, skill_id),
    CONSTRAINT fk_techskill_tech  FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE,
    CONSTRAINT fk_techskill_skill FOREIGN KEY (skill_id)      REFERENCES skills(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zones assignées à un technicien (sous-ensemble des service_zones).
CREATE TABLE technician_zones (
    technician_id BIGINT UNSIGNED NOT NULL,
    zone_id       BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (technician_id, zone_id),
    CONSTRAINT fk_techzone_tech FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE,
    CONSTRAINT fk_techzone_zone FOREIGN KEY (zone_id)       REFERENCES service_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Disponibilité récurrente : une ligne par plage hebdomadaire.
CREATE TABLE technician_availability (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    technician_id BIGINT UNSIGNED NOT NULL,
    weekday       TINYINT         NOT NULL,   -- 0=dimanche … 6=samedi
    start_time    TIME            NOT NULL,   -- heure locale Europe/Brussels
    end_time      TIME            NOT NULL,
    -- rattachement optionnel à un atelier (dispo terrain vs dispo atelier).
    location_id   BIGINT UNSIGNED NULL,
    valid_from    DATE            NULL,
    valid_until   DATE            NULL,
    PRIMARY KEY (id),
    KEY idx_techavail_tech (technician_id, weekday),
    CONSTRAINT fk_techavail_tech     FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE,
    CONSTRAINT fk_techavail_location FOREIGN KEY (location_id)   REFERENCES locations(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Absences / congés ponctuels (bloque la génération de créneaux).
CREATE TABLE technician_time_off (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    technician_id BIGINT UNSIGNED NOT NULL,
    starts_at     DATETIME        NOT NULL,   -- UTC
    ends_at       DATETIME        NOT NULL,
    reason        VARCHAR(190)    NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_techtimeoff_tech (technician_id, starts_at),
    CONSTRAINT fk_techtimeoff_tech FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicles (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120)    NOT NULL,
    plate         VARCHAR(20)     NULL,
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  6. PANIER (CART) — anonyme, à token, avec expiration
-- =============================================================================

CREATE TABLE carts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token         CHAR(40)        NOT NULL,   -- token anonyme (URL/localStorage)
    customer_id   BIGINT UNSIGNED NULL,
    -- adresse & mode saisis au fil du tunnel (dénormalisés le temps du panier)
    postal_code   VARCHAR(12)     NULL,
    address_id    BIGINT UNSIGNED NULL,
    coupon_id     BIGINT UNSIGNED NULL,
    -- statut : actif, converti (→ booking), expiré, abandonné.
    status        ENUM('active','converted','expired','abandoned') NOT NULL DEFAULT 'active',
    expires_at    DATETIME        NOT NULL,   -- +7 jours par défaut
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_carts_token (token),
    KEY idx_carts_status (status, expires_at),
    CONSTRAINT fk_carts_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_carts_address  FOREIGN KEY (address_id)  REFERENCES addresses(id) ON DELETE SET NULL,
    CONSTRAINT fk_carts_coupon   FOREIGN KEY (coupon_id)   REFERENCES coupons(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une ligne de panier = un service + variante + mode + quantité, prix/durée FIGÉS.
CREATE TABLE cart_items (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id            BIGINT UNSIGNED NOT NULL,
    service_id         BIGINT UNSIGNED NOT NULL,
    variant_id         BIGINT UNSIGNED NULL,
    mode               ENUM('onsite','workshop') NOT NULL,
    quantity           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- Prix/durée figés au moment de l'ajout (revalidés à la soumission).
    unit_price_cents   INT             NOT NULL DEFAULT 0,
    unit_duration_min  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- Libellé figé pour l'affichage même si le catalogue change ensuite.
    label_snapshot     VARCHAR(255)    NULL,
    config_snapshot    JSON            NULL,   -- config lisible (gabarit, matière…)
    sort_order         INT             NOT NULL DEFAULT 0,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cartitems_cart (cart_id),
    CONSTRAINT fk_cartitems_cart    FOREIGN KEY (cart_id)    REFERENCES carts(id)            ON DELETE CASCADE,
    CONSTRAINT fk_cartitems_service FOREIGN KEY (service_id) REFERENCES services(id)         ON DELETE RESTRICT,
    CONSTRAINT fk_cartitems_variant FOREIGN KEY (variant_id) REFERENCES service_variants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cart_item_extras (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_item_id       BIGINT UNSIGNED NOT NULL,
    extra_id           BIGINT UNSIGNED NOT NULL,
    unit_price_cents   INT             NOT NULL DEFAULT 0,
    unit_duration_min  SMALLINT        NOT NULL DEFAULT 0,
    label_snapshot     VARCHAR(190)    NULL,
    PRIMARY KEY (id),
    KEY idx_cartitemextras_item (cart_item_id),
    CONSTRAINT fk_cartitemextra_item  FOREIGN KEY (cart_item_id) REFERENCES cart_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_cartitemextra_extra FOREIGN KEY (extra_id)     REFERENCES extras(id)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  7. RÉSERVATIONS — hiérarchie à trois niveaux
--     bookings (commande) > booking_items (lignes) > jobs (interventions)
-- =============================================================================

CREATE TABLE bookings (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference          VARCHAR(20)     NOT NULL,   -- ex. 'KN-2026-000123'
    customer_id        BIGINT UNSIGNED NOT NULL,
    address_id         BIGINT UNSIGNED NULL,       -- adresse d'intervention (onsite)
    source_cart_id     BIGINT UNSIGNED NULL,
    booking_mode       ENUM('instant','request') NOT NULL DEFAULT 'instant',
    -- statut global de la commande.
    status             ENUM('draft','pending','confirmed','in_progress','completed','cancelled','no_show')
                       NOT NULL DEFAULT 'pending',
    -- statut de paiement (NullGateway au lancement → 'not_required').
    payment_status     ENUM('not_required','pending','paid','partially_paid','refunded','failed')
                       NOT NULL DEFAULT 'not_required',
    -- Totaux figés (centimes). Détail : sous-total, remises, supplément, TVA.
    subtotal_cents     INT             NOT NULL DEFAULT 0,
    discount_cents     INT             NOT NULL DEFAULT 0,   -- remises cumul + coupon
    travel_surcharge_cents INT         NOT NULL DEFAULT 0,
    vat_cents          INT             NOT NULL DEFAULT 0,
    total_cents        INT             NOT NULL DEFAULT 0,   -- TVAC
    vat_rate_bp        INT             NOT NULL DEFAULT 2100, -- 21.00% en points de base
    coupon_id          BIGINT UNSIGNED NULL,
    -- CGV + RGPD archivés avec la commande.
    terms_version_id   BIGINT UNSIGNED NULL,
    total_duration_min INT             NOT NULL DEFAULT 0,
    -- Lien de gestion client (report/annulation) via token signé, sans compte.
    manage_token       CHAR(48)        NULL,
    notes              TEXT            NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bookings_reference (reference),
    UNIQUE KEY uq_bookings_manage_token (manage_token),
    KEY idx_bookings_customer (customer_id),
    KEY idx_bookings_status (status, created_at),
    CONSTRAINT fk_bookings_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_address  FOREIGN KEY (address_id)  REFERENCES addresses(id) ON DELETE SET NULL,
    CONSTRAINT fk_bookings_coupon   FOREIGN KEY (coupon_id)   REFERENCES coupons(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lignes de la commande (issues du panier), prix/durée figés.
CREATE TABLE booking_items (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id         BIGINT UNSIGNED NOT NULL,
    job_id             BIGINT UNSIGNED NULL,     -- job auquel la ligne est rattachée
    service_id         BIGINT UNSIGNED NOT NULL,
    variant_id         BIGINT UNSIGNED NULL,
    mode               ENUM('onsite','workshop') NOT NULL,
    quantity           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    unit_price_cents   INT             NOT NULL DEFAULT 0,
    unit_duration_min  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    line_total_cents   INT             NOT NULL DEFAULT 0,
    label_snapshot     VARCHAR(255)    NULL,
    config_snapshot    JSON            NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bookingitems_booking (booking_id),
    KEY idx_bookingitems_job (job_id),
    CONSTRAINT fk_bookingitems_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_bookingitems_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT,
    CONSTRAINT fk_bookingitems_variant FOREIGN KEY (variant_id) REFERENCES service_variants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_item_extras (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_item_id  BIGINT UNSIGNED NOT NULL,
    extra_id         BIGINT UNSIGNED NOT NULL,
    unit_price_cents INT             NOT NULL DEFAULT 0,
    unit_duration_min SMALLINT       NOT NULL DEFAULT 0,
    label_snapshot   VARCHAR(190)    NULL,
    PRIMARY KEY (id),
    KEY idx_bookingitemextras_item (booking_item_id),
    CONSTRAINT fk_bookingitemextra_item  FOREIGN KEY (booking_item_id) REFERENCES booking_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_bookingitemextra_extra FOREIGN KEY (extra_id)        REFERENCES extras(id)         ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jobs = interventions planifiables. Une commande peut en générer plusieurs
-- (mélange domicile/atelier, ou fractionnement d'une durée trop longue).
CREATE TABLE jobs (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id         BIGINT UNSIGNED NOT NULL,
    mode               ENUM('onsite','workshop') NOT NULL,
    location_id        BIGINT UNSIGNED NULL,    -- atelier si mode workshop
    bay_id             BIGINT UNSIGNED NULL,    -- poste réservé si mode workshop
    technician_id      BIGINT UNSIGNED NULL,    -- assigné (peut rester NULL en 'request')
    address_id         BIGINT UNSIGNED NULL,    -- adresse d'intervention si onsite
    -- Créneau planifié (UTC). Onsite : fenêtre d'arrivée. Workshop : dépôt→reprise.
    scheduled_start    DATETIME        NULL,
    scheduled_end      DATETIME        NULL,
    -- Fenêtre d'arrivée affichée au client (onsite), ex. arrival_from 09:00.
    arrival_from       DATETIME        NULL,
    arrival_to         DATETIME        NULL,
    active_duration_min  SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- travail actif
    occupancy_duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,-- immobilisation poste
    -- Trajet estimé depuis le job précédent (min), pour information dispatch.
    travel_in_min      SMALLINT        NULL,
    travel_out_min     SMALLINT        NULL,
    status             ENUM('unscheduled','scheduled','en_route','in_progress','completed','cancelled','no_show')
                       NOT NULL DEFAULT 'unscheduled',
    sequence_no        SMALLINT        NOT NULL DEFAULT 1,   -- ordre au sein de la commande
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_jobs_booking (booking_id),
    KEY idx_jobs_tech_start (technician_id, scheduled_start),   -- (technician_id, starts_at)
    KEY idx_jobs_status_start (status, scheduled_start),        -- (status, starts_at)
    KEY idx_jobs_bay_start (bay_id, scheduled_start),
    KEY idx_jobs_location (location_id),
    CONSTRAINT fk_jobs_booking  FOREIGN KEY (booking_id)    REFERENCES bookings(id)     ON DELETE CASCADE,
    CONSTRAINT fk_jobs_location FOREIGN KEY (location_id)   REFERENCES locations(id)    ON DELETE SET NULL,
    CONSTRAINT fk_jobs_bay      FOREIGN KEY (bay_id)        REFERENCES workshop_bays(id) ON DELETE SET NULL,
    CONSTRAINT fk_jobs_tech     FOREIGN KEY (technician_id) REFERENCES technicians(id)  ON DELETE SET NULL,
    CONSTRAINT fk_jobs_address  FOREIGN KEY (address_id)    REFERENCES addresses(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Affectations (peut couvrir co-équipiers, véhicule) — au-delà du technicien
-- principal porté par jobs.technician_id.
CREATE TABLE assignments (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id        BIGINT UNSIGNED NOT NULL,
    technician_id BIGINT UNSIGNED NULL,
    vehicle_id    BIGINT UNSIGNED NULL,
    role          ENUM('lead','helper') NOT NULL DEFAULT 'lead',
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assignments_job (job_id),
    CONSTRAINT fk_assign_job     FOREIGN KEY (job_id)        REFERENCES jobs(id)        ON DELETE CASCADE,
    CONSTRAINT fk_assign_tech    FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE SET NULL,
    CONSTRAINT fk_assign_vehicle FOREIGN KEY (vehicle_id)    REFERENCES vehicles(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réponses au formulaire dynamique (une fois pour l'ensemble du panier/commande).
CREATE TABLE booking_answers (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id    BIGINT UNSIGNED NOT NULL,
    field_id      BIGINT UNSIGNED NULL,       -- référence form_fields (peut être NULL)
    field_key     VARCHAR(120)    NOT NULL,   -- clé stable ('water_access', 'dirt_level'…)
    value_text    TEXT            NULL,
    value_json    JSON            NULL,        -- pour choix multiples
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bookinganswers_booking (booking_id),
    CONSTRAINT fk_bookinganswers_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_status_history (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id    BIGINT UNSIGNED NOT NULL,
    job_id        BIGINT UNSIGNED NULL,
    old_status    VARCHAR(40)     NULL,
    new_status    VARCHAR(40)     NOT NULL,
    changed_by    BIGINT UNSIGNED NULL,       -- user (NULL = système/client)
    note          VARCHAR(255)    NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_statushist_booking (booking_id, created_at),
    CONSTRAINT fk_statushist_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_statushist_job     FOREIGN KEY (job_id)     REFERENCES jobs(id)     ON DELETE SET NULL,
    CONSTRAINT fk_statushist_user    FOREIGN KEY (changed_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_photos (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id    BIGINT UNSIGNED NOT NULL,
    job_id        BIGINT UNSIGNED NULL,
    kind          ENUM('before','after','intake') NOT NULL,
    file_path     VARCHAR(255)    NOT NULL,   -- stockage hors webroot
    uploaded_by   BIGINT UNSIGNED NULL,
    consent_given TINYINT(1)      NOT NULL DEFAULT 0,  -- consentement pour usage vitrine
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bookingphotos_booking (booking_id),
    CONSTRAINT fk_bookingphotos_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_bookingphotos_job     FOREIGN KEY (job_id)     REFERENCES jobs(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réservation temporaire d'un créneau (hold) pendant le tunnel — anti double-résa.
-- Purge par cron au bout de ~10 min. Verrou logique complémentaire du SELECT ... FOR UPDATE.
CREATE TABLE slot_holds (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id       BIGINT UNSIGNED NULL,
    technician_id BIGINT UNSIGNED NULL,
    bay_id        BIGINT UNSIGNED NULL,
    mode          ENUM('onsite','workshop') NOT NULL,
    starts_at     DATETIME        NOT NULL,   -- UTC
    ends_at       DATETIME        NOT NULL,
    expires_at    DATETIME        NOT NULL,   -- +10 min
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_holds_tech_start (technician_id, starts_at),
    KEY idx_holds_bay_start (bay_id, starts_at),
    KEY idx_holds_expiry (expires_at),
    CONSTRAINT fk_holds_cart FOREIGN KEY (cart_id)       REFERENCES carts(id)       ON DELETE CASCADE,
    CONSTRAINT fk_holds_tech FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE,
    CONSTRAINT fk_holds_bay  FOREIGN KEY (bay_id)        REFERENCES workshop_bays(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  8. MOTEUR DE DISPONIBILITÉ — CACHES & MATRICE DE REPLI
-- =============================================================================

-- Cache agressif des temps de trajet (Distance Matrix / OpenRouteService).
-- Clé = hash(origin,destination,mode). TTL par défaut 30 jours.
CREATE TABLE geo_distance_cache (
    cache_key     CHAR(64)        NOT NULL,   -- hash SHA-256
    origin_lat    DECIMAL(10,7)   NULL,
    origin_lng    DECIMAL(10,7)   NULL,
    dest_lat      DECIMAL(10,7)   NULL,
    dest_lng      DECIMAL(10,7)   NULL,
    travel_mode   VARCHAR(20)     NOT NULL DEFAULT 'driving',
    duration_sec  INT             NOT NULL,
    distance_m    INT             NULL,
    provider      VARCHAR(40)     NOT NULL,   -- 'ors' | 'google' | 'postal_matrix'
    expires_at    DATETIME        NOT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cache_key),
    KEY idx_geocache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Matrice de repli code postal → code postal (si l'API est indisponible).
CREATE TABLE postal_travel_matrix (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_postal   VARCHAR(12)     NOT NULL,
    to_postal     VARCHAR(12)     NOT NULL,
    duration_min  SMALLINT UNSIGNED NOT NULL,
    distance_km   DECIMAL(6,2)    NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_postal_pair (from_postal, to_postal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  9. FORMULAIRE DYNAMIQUE (FORM BUILDER)
-- =============================================================================

CREATE TABLE form_versions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version       INT             NOT NULL,
    label         VARCHAR(160)    NULL,
    is_published  TINYINT(1)      NOT NULL DEFAULT 0,
    published_at  DATETIME        NULL,
    created_by    BIGINT UNSIGNED NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_form_version (version),
    CONSTRAINT fk_formversion_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_fields (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_id    BIGINT UNSIGNED NOT NULL,
    field_key     VARCHAR(120)    NOT NULL,   -- clé stable réutilisée dans booking_answers
    label         VARCHAR(255)    NOT NULL,
    help_text     VARCHAR(255)    NULL,
    field_type    ENUM('text','textarea','number','select','radio','checkbox',
                       'cards','stepper','date','photo','address','coupon','consent') NOT NULL,
    step          SMALLINT        NOT NULL DEFAULT 1,   -- étape du tunnel
    sort_order    INT             NOT NULL DEFAULT 0,
    is_required   TINYINT(1)      NOT NULL DEFAULT 0,
    -- modificateur tarifaire/durée porté par le champ lui-même (rare).
    price_modifier_type ENUM('none','fixed','percent','multiplier') NOT NULL DEFAULT 'none',
    price_modifier_value INT      NOT NULL DEFAULT 0,
    duration_modifier_type ENUM('none','fixed','percent','multiplier') NOT NULL DEFAULT 'none',
    duration_modifier_value INT   NOT NULL DEFAULT 0,
    config_json   JSON            NULL,        -- inputmode, autocomplete, min/max…
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_formfield_version_key (version_id, field_key),
    KEY idx_formfields_version (version_id, step, sort_order),
    CONSTRAINT fk_formfields_version FOREIGN KEY (version_id) REFERENCES form_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_field_options (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    field_id      BIGINT UNSIGNED NOT NULL,
    value         VARCHAR(160)    NOT NULL,
    label         VARCHAR(255)    NOT NULL,
    image_path    VARCHAR(255)    NULL,        -- pour les cartes visuelles / échelle salissure
    -- modificateur porté par l'option (ex. niveau de salissure → +durée).
    price_modifier_type ENUM('none','fixed','percent','multiplier') NOT NULL DEFAULT 'none',
    price_modifier_value INT      NOT NULL DEFAULT 0,
    duration_modifier_type ENUM('none','fixed','percent','multiplier') NOT NULL DEFAULT 'none',
    duration_modifier_value INT   NOT NULL DEFAULT 0,
    sort_order    INT             NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_formoptions_field (field_id, sort_order),
    CONSTRAINT fk_formoptions_field FOREIGN KEY (field_id) REFERENCES form_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Logique conditionnelle : SI field source = valeur ALORS action sur field cible.
CREATE TABLE form_conditions (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_id     BIGINT UNSIGNED NOT NULL,
    source_field_id BIGINT UNSIGNED NOT NULL,
    operator       ENUM('eq','neq','in','gt','lt','filled','empty') NOT NULL DEFAULT 'eq',
    compare_value  VARCHAR(255)    NULL,
    action         ENUM('show','hide','require','optional') NOT NULL,
    target_field_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_formcond_version (version_id),
    CONSTRAINT fk_formcond_version FOREIGN KEY (version_id)      REFERENCES form_versions(id) ON DELETE CASCADE,
    CONSTRAINT fk_formcond_source  FOREIGN KEY (source_field_id) REFERENCES form_fields(id)   ON DELETE CASCADE,
    CONSTRAINT fk_formcond_target  FOREIGN KEY (target_field_id) REFERENCES form_fields(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignation d'un champ à des prestations spécifiques. Aucune ligne pour un
-- champ = ce champ s'applique à toutes les prestations (comportement par défaut).
CREATE TABLE form_field_services (
    field_id   BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (field_id, service_id),
    CONSTRAINT fk_ffs_field   FOREIGN KEY (field_id)   REFERENCES form_fields(id) ON DELETE CASCADE,
    CONSTRAINT fk_ffs_service FOREIGN KEY (service_id) REFERENCES services(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  10. OPÉRATIONNEL & FINANCE
-- =============================================================================

CREATE TABLE invoices (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id      BIGINT UNSIGNED NULL,
    customer_id     BIGINT UNSIGNED NOT NULL,
    -- Numérotation séquentielle SANS TROU (obligation TVA belge).
    number          VARCHAR(30)     NOT NULL,   -- ex. '2026-000042'
    invoice_type    ENUM('simplified','full') NOT NULL DEFAULT 'full',  -- <250€ TVAC = simplifiée
    status          ENUM('draft','issued','paid','cancelled','credited') NOT NULL DEFAULT 'draft',
    issued_at       DATETIME        NULL,
    due_at          DATETIME        NULL,
    subtotal_cents  INT             NOT NULL DEFAULT 0,
    vat_cents       INT             NOT NULL DEFAULT 0,
    total_cents     INT             NOT NULL DEFAULT 0,
    vat_rate_bp     INT             NOT NULL DEFAULT 2100,
    -- Peppol / UBL BIS 3.0 : suivi de l'export B2B.
    peppol_status   ENUM('not_applicable','pending','sent','failed') NOT NULL DEFAULT 'not_applicable',
    peppol_sent_at  DATETIME        NULL,
    ubl_path        VARCHAR(255)    NULL,
    pdf_path        VARCHAR(255)    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoices_number (number),
    KEY idx_invoices_customer (customer_id),
    KEY idx_invoices_booking (booking_id),
    CONSTRAINT fk_invoices_booking  FOREIGN KEY (booking_id)  REFERENCES bookings(id)  ON DELETE SET NULL,
    CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_lines (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id     BIGINT UNSIGNED NOT NULL,
    description    VARCHAR(255)    NOT NULL,
    quantity       DECIMAL(8,2)    NOT NULL DEFAULT 1,
    unit_price_cents INT           NOT NULL DEFAULT 0,
    vat_rate_bp    INT             NOT NULL DEFAULT 2100,
    line_total_cents INT           NOT NULL DEFAULT 0,    -- HTVA
    sort_order     INT             NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_invoicelines_invoice (invoice_id),
    CONSTRAINT fk_invoicelines_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paiements : table présente dès le lancement (NullGateway), prête pour Mollie/Stripe.
CREATE TABLE payments (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id     BIGINT UNSIGNED NULL,
    invoice_id     BIGINT UNSIGNED NULL,
    gateway        VARCHAR(40)     NOT NULL DEFAULT 'null',  -- 'null'|'mollie'|'stripe'
    external_ref   VARCHAR(120)    NULL,
    method         VARCHAR(40)     NULL,       -- 'bancontact','card','cash','transfer'
    amount_cents   INT             NOT NULL DEFAULT 0,
    currency       CHAR(3)         NOT NULL DEFAULT 'EUR',
    status         ENUM('pending','authorized','paid','failed','refunded','cancelled') NOT NULL DEFAULT 'pending',
    paid_at        DATETIME        NULL,
    -- Encaissement sur place par le technicien (app technicien).
    collected_by   BIGINT UNSIGNED NULL,
    payload_json   JSON            NULL,        -- réponse brute passerelle
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payments_booking (booking_id),
    KEY idx_payments_status (status),
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_user    FOREIGN KEY (collected_by) REFERENCES users(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pointage CP 121 : horodatage début/fin non modifiable sans trace d'audit.
CREATE TABLE time_entries (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    technician_id  BIGINT UNSIGNED NOT NULL,
    job_id         BIGINT UNSIGNED NULL,
    entry_type     ENUM('start','stop','pause','resume') NOT NULL,
    occurred_at    DATETIME        NOT NULL,    -- UTC, horodatage réel
    lat            DECIMAL(10,7)   NULL,        -- géolocalisation du pointage
    lng            DECIMAL(10,7)   NULL,
    -- immuabilité : toute correction crée une nouvelle ligne + entrée audit_log.
    is_corrected   TINYINT(1)      NOT NULL DEFAULT 0,
    corrects_id    BIGINT UNSIGNED NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_timeentries_tech (technician_id, occurred_at),
    KEY idx_timeentries_job (job_id),
    CONSTRAINT fk_timeentries_tech FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE RESTRICT,
    CONSTRAINT fk_timeentries_job  FOREIGN KEY (job_id)        REFERENCES jobs(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indemnités de mobilité CP 121 (barème paramétrable via settings).
CREATE TABLE mobility_allowances (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    technician_id  BIGINT UNSIGNED NOT NULL,
    job_id         BIGINT UNSIGNED NULL,
    work_date      DATE            NOT NULL,
    distance_km    DECIMAL(7,2)    NOT NULL DEFAULT 0,
    rate_cents_per_km INT          NOT NULL DEFAULT 0,   -- barème figé au calcul
    amount_cents   INT             NOT NULL DEFAULT 0,
    exported_at    DATETIME        NULL,                 -- export mensuel secrétariat social
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mobility_tech_date (technician_id, work_date),
    CONSTRAINT fk_mobility_tech FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE RESTRICT,
    CONSTRAINT fk_mobility_job  FOREIGN KEY (job_id)        REFERENCES jobs(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  11. NOTIFICATIONS (moteur d'événements configurable)
-- =============================================================================

CREATE TABLE notification_events (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key      VARCHAR(80)     NOT NULL,   -- booking_created, reminder_48h…
    label          VARCHAR(190)    NOT NULL,
    is_active      TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifevent_key (event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_templates (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id       BIGINT UNSIGNED NOT NULL,
    channel        ENUM('email','sms') NOT NULL,
    -- délai relatif à l'événement en minutes (ex. -2880 = 48h avant le RDV).
    offset_minutes INT             NOT NULL DEFAULT 0,
    subject        VARCHAR(255)    NULL,       -- email uniquement
    body           TEXT            NOT NULL,   -- variables {{...}}
    is_active      TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_notiftpl_event (event_id),
    CONSTRAINT fk_notiftpl_event FOREIGN KEY (event_id) REFERENCES notification_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications_log (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id     BIGINT UNSIGNED NULL,
    event_key      VARCHAR(80)     NOT NULL,
    channel        ENUM('email','sms') NOT NULL,
    recipient      VARCHAR(190)    NOT NULL,
    status         ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
    provider_ref   VARCHAR(120)    NULL,
    error          VARCHAR(255)    NULL,
    scheduled_at   DATETIME        NULL,
    sent_at        DATETIME        NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notiflog_booking (booking_id),
    KEY idx_notiflog_status (status, scheduled_at),
    CONSTRAINT fk_notiflog_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  12. RGPD, CGV & AUDIT
-- =============================================================================

-- Versions des conditions générales (CGV) — archivées avec chaque commande.
CREATE TABLE terms_versions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version       VARCHAR(20)     NOT NULL,
    body          MEDIUMTEXT      NOT NULL,
    published_at  DATETIME        NULL,
    is_current    TINYINT(1)      NOT NULL DEFAULT 0,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_terms_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consentements RGPD : explicites, horodatés et versionnés.
CREATE TABLE consents (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id   BIGINT UNSIGNED NULL,
    booking_id    BIGINT UNSIGNED NULL,
    consent_type  VARCHAR(80)     NOT NULL,   -- 'privacy','terms','marketing','photos'
    policy_version VARCHAR(20)    NULL,
    granted       TINYINT(1)      NOT NULL DEFAULT 1,
    ip_address    VARBINARY(16)   NULL,       -- INET6_ATON
    user_agent    VARCHAR(255)    NULL,
    granted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_consents_customer (customer_id),
    KEY idx_consents_booking (booking_id),
    CONSTRAINT fk_consents_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_consents_booking  FOREIGN KEY (booking_id)  REFERENCES bookings(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal d'audit : toute action admin sensible + corrections de pointage.
CREATE TABLE audit_log (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NULL,
    action        VARCHAR(120)    NOT NULL,   -- ex. 'booking.cancel', 'time_entry.correct'
    entity_type   VARCHAR(80)     NULL,
    entity_id     BIGINT UNSIGNED NULL,
    old_values    JSON            NULL,
    new_values    JSON            NULL,
    ip_address    VARBINARY(16)   NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sécurité : limitation de débit (login, formulaire public, endpoint dispo).
CREATE TABLE rate_limits (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket        VARCHAR(120)    NOT NULL,   -- ex. 'login:ip:1.2.3.4', 'availability:ip'
    hits          INT             NOT NULL DEFAULT 0,
    window_start  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ratelimit_bucket_window (bucket, window_start),
    KEY idx_ratelimit_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
--  FIN DU SCHÉMA
-- =============================================================================
