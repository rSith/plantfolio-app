-- PlantFolio database schema: creates all 18 tables, 26 foreign keys, unique keys, checks and indexes (CORE-01).
-- Source of truth: database/data-dictionary.md. If the two disagree, fix this file to match the dictionary.
--
-- Run it on the `plantfolio` database (select it first in phpMyAdmin, or: mysql -u root plantfolio < database/schema.sql).
-- It is re-runnable: every table is dropped first, so each run gives the same empty database.
-- WARNING: that also means running it deletes all data. Load database/seed.sql afterwards for demo data.
--
-- Written for MySQL 8 and MariaDB 10.4 (XAMPP). On MariaDB, JSON is stored as LONGTEXT with a JSON_VALID check.

SET NAMES utf8mb4;

-- Foreign key checks are switched off only while dropping, so tables can be dropped in any order.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS care_guides, listing_categories, user_ratings, listing_interests, listing_photos,
    exchange_listings, disease_guides, plant_likes, care_reminders, health_logs, ml_predictions,
    plant_photos, plants, plant_species, collections, remember_tokens, users, towns;
SET FOREIGN_KEY_CHECKS = 1;

-- Tables are created parents first: a foreign key can only point at a table that already exists.

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. towns: reference data, used for "3 km away" and the distance filter (DES-07)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE towns (
    id        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name      VARCHAR(100)  NOT NULL,
    latitude  DECIMAL(9,6)  NOT NULL,
    longitude DECIMAL(9,6)  NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_towns_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. users: accounts, public profiles and Settings choices
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE users (
    id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    username            VARCHAR(30)      NOT NULL,
    full_name           VARCHAR(100)     NOT NULL,
    email               VARCHAR(255)     NOT NULL,
    phone               VARCHAR(20)      NULL DEFAULT NULL,
    password_hash       VARCHAR(255)     NOT NULL,
    bio                 VARCHAR(500)     NULL DEFAULT NULL,
    town_id             INT UNSIGNED     NULL DEFAULT NULL,
    km_from_town        TINYINT UNSIGNED NULL DEFAULT NULL,
    avatar              VARCHAR(255)     NULL DEFAULT NULL,
    new_plants_public   TINYINT(1)       NOT NULL DEFAULT 1,
    dashboard_reminders TINYINT(1)       NOT NULL DEFAULT 1,
    email_reminders     TINYINT(1)       NOT NULL DEFAULT 0,
    failed_logins       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until        DATETIME         NULL DEFAULT NULL,
    created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_town (town_id),
    CONSTRAINT chk_users_km CHECK (km_from_town BETWEEN 0 AND 30),
    -- RESTRICT: a town still used by a member cannot be deleted.
    CONSTRAINT fk_users_town FOREIGN KEY (town_id) REFERENCES towns (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. remember_tokens: "Remember me" logins; only a hash of the secret is stored (DES-07)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE remember_tokens (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    selector   CHAR(24)     NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    expires_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_remember_selector (selector),
    KEY idx_remember_user (user_id),
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. collections: a member's named groups of plants
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE collections (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    name       VARCHAR(60)  NOT NULL,
    is_public  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- user_id comes first in this key, so it also serves as the index for the foreign key.
    UNIQUE KEY uq_collections_user_name (user_id, name),
    CONSTRAINT fk_collections_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. plant_species: reference care data for each species
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE plant_species (
    id                      INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    common_name             VARCHAR(100)      NOT NULL,
    scientific_name         VARCHAR(150)      NOT NULL,
    photo                   VARCHAR(255)      NULL DEFAULT NULL,
    light                   VARCHAR(100)      NULL DEFAULT NULL,
    water                   VARCHAR(150)      NULL DEFAULT NULL,
    humidity                VARCHAR(60)       NULL DEFAULT NULL,
    pet_safe                TINYINT(1)        NULL DEFAULT NULL,   -- NULL = unknown
    water_interval_days     SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    fertilise_interval_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    notes                   TEXT              NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_species_scientific (scientific_name),
    KEY idx_species_common (common_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. plants: each plant profile
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE plants (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    collection_id  INT UNSIGNED NULL DEFAULT NULL,
    species_id     INT UNSIGNED NULL DEFAULT NULL,
    custom_species VARCHAR(150) NULL DEFAULT NULL,   -- a typed name with no plant_species row; never set with species_id
    nickname       VARCHAR(60)  NOT NULL,
    photo          VARCHAR(255) NULL DEFAULT NULL,
    acquired_on    DATE         NULL DEFAULT NULL,
    health_status  ENUM('thriving','stable','needs_attention','sick') NOT NULL DEFAULT 'stable',
    is_public      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_plants_user (user_id),
    KEY idx_plants_collection (collection_id),
    KEY idx_plants_species (species_id),
    KEY idx_plants_health (health_status),
    KEY idx_plants_public (is_public),
    CONSTRAINT fk_plants_user       FOREIGN KEY (user_id)       REFERENCES users (id)         ON DELETE CASCADE,
    -- SET NULL: deleting a collection keeps its plants; deleting a species keeps the plant as "unknown".
    CONSTRAINT fk_plants_collection FOREIGN KEY (collection_id) REFERENCES collections (id)   ON DELETE SET NULL,
    CONSTRAINT fk_plants_species    FOREIGN KEY (species_id)    REFERENCES plant_species (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 7. plant_photos: extra photos for the Plant Profile gallery
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE plant_photos (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plant_id   INT UNSIGNED NOT NULL,
    path       VARCHAR(255) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_plant_photos_plant (plant_id),
    CONSTRAINT fk_plant_photos_plant FOREIGN KEY (plant_id) REFERENCES plants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 11. ml_predictions: every recognition or diagnosis, saved or not
-- Created before health_logs, because a health log can point at a prediction.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE ml_predictions (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED  NOT NULL,
    plant_id        INT UNSIGNED  NULL DEFAULT NULL,
    type            ENUM('species','disease') NOT NULL,
    predicted_label VARCHAR(150)  NOT NULL,
    confidence      DECIMAL(5,4)  NOT NULL,
    alternatives    JSON          NULL DEFAULT NULL,
    image_path      VARCHAR(255)  NOT NULL,
    upload_name     VARCHAR(255)  NULL DEFAULT NULL,
    upload_size     INT UNSIGNED  NULL DEFAULT NULL,
    model_version   VARCHAR(50)   NOT NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_predictions_user (user_id),
    KEY idx_predictions_plant (plant_id),
    KEY idx_predictions_label (predicted_label),
    CONSTRAINT chk_predictions_confidence CHECK (confidence BETWEEN 0 AND 1),
    CONSTRAINT fk_predictions_user  FOREIGN KEY (user_id)  REFERENCES users (id)  ON DELETE CASCADE,
    CONSTRAINT fk_predictions_plant FOREIGN KEY (plant_id) REFERENCES plants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 8. health_logs: the dated plant Story
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE health_logs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plant_id      INT UNSIGNED NOT NULL,
    prediction_id INT UNSIGNED NULL DEFAULT NULL,
    type          ENUM('note','photo','care','health_check','milestone') NOT NULL DEFAULT 'note',
    care_task     ENUM('water','fertilise','mist','rotate','prune','repot') NULL DEFAULT NULL,
    status        ENUM('thriving','stable','needs_attention','sick') NULL DEFAULT NULL,
    source        ENUM('manual','ml') NOT NULL DEFAULT 'manual',
    note          VARCHAR(500) NULL DEFAULT NULL,
    photo         VARCHAR(255) NULL DEFAULT NULL,
    logged_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One index serves both the foreign key and "this plant's story, newest first".
    KEY idx_health_logs_plant_date (plant_id, logged_at),
    KEY idx_health_logs_prediction (prediction_id),
    CONSTRAINT fk_health_logs_plant      FOREIGN KEY (plant_id)      REFERENCES plants (id)         ON DELETE CASCADE,
    CONSTRAINT fk_health_logs_prediction FOREIGN KEY (prediction_id) REFERENCES ml_predictions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 9. care_reminders: one reminder per task per plant
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE care_reminders (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    plant_id      INT UNSIGNED      NOT NULL,
    task          ENUM('water','fertilise','mist','rotate','prune','repot','recheck') NOT NULL,
    remind_time   TIME              NOT NULL DEFAULT '08:00:00',
    repeat_type   ENUM('once','daily','days','interval') NOT NULL DEFAULT 'interval',
    repeat_days   SET('mon','tue','wed','thu','fri','sat','sun') NULL DEFAULT NULL,
    interval_days SMALLINT UNSIGNED NULL DEFAULT NULL,
    last_done     DATE              NULL DEFAULT NULL,
    next_due      DATE              NOT NULL,
    PRIMARY KEY (id),
    -- Saving a reminder for a task the plant already has replaces it (INSERT ... ON DUPLICATE KEY UPDATE).
    UNIQUE KEY uq_reminders_plant_task (plant_id, task),
    KEY idx_reminders_next_due (next_due),
    CONSTRAINT chk_reminders_interval CHECK (interval_days > 0),
    CONSTRAINT fk_reminders_plant FOREIGN KEY (plant_id) REFERENCES plants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 10. plant_likes: one like per member per plant
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE plant_likes (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plant_id   INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plant_likes (plant_id, user_id),
    KEY idx_plant_likes_user (user_id),
    CONSTRAINT fk_plant_likes_plant FOREIGN KEY (plant_id) REFERENCES plants (id) ON DELETE CASCADE,
    CONSTRAINT fk_plant_likes_user  FOREIGN KEY (user_id)  REFERENCES users (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 12. disease_guides: what the Health Check says about each disease class
-- Matched to ml_predictions.predicted_label by text, so it has no foreign key.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE disease_guides (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_label VARCHAR(100) NOT NULL,
    crop        VARCHAR(50)  NOT NULL,
    cause       VARCHAR(255) NULL DEFAULT NULL,
    symptoms    TEXT         NULL,
    steps       JSON         NULL DEFAULT NULL,
    status      ENUM('thriving','stable','needs_attention','sick') NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_disease_guides_label (class_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 13. exchange_listings: plants offered for swap or free
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE exchange_listings (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED     NOT NULL,
    plant_id        INT UNSIGNED     NULL DEFAULT NULL,
    species_id      INT UNSIGNED     NULL DEFAULT NULL,
    title           VARCHAR(100)     NOT NULL,
    description     TEXT             NULL,
    type            ENUM('swap','free','either') NOT NULL DEFAULT 'swap',
    looking_for     VARCHAR(150)     NULL DEFAULT NULL,
    town_id         INT UNSIGNED     NOT NULL,
    km_from_town    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    size            VARCHAR(60)      NULL DEFAULT NULL,
    plant_condition VARCHAR(100)     NULL DEFAULT NULL,   -- not `condition`: that is a reserved word in MySQL
    light_level     ENUM('low','medium','bright') NULL DEFAULT NULL,      -- NULL = "Not sure"
    water_need      ENUM('low','moderate','high') NULL DEFAULT NULL,      -- NULL = "Not sure"
    photo           VARCHAR(255)     NOT NULL,
    share_email     TINYINT(1)       NOT NULL DEFAULT 1,
    share_phone     TINYINT(1)       NOT NULL DEFAULT 0,
    status          ENUM('open','closed') NOT NULL DEFAULT 'open',
    exchanged_with  INT UNSIGNED     NULL DEFAULT NULL,
    exchanged_at    DATETIME         NULL DEFAULT NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_listings_user (user_id),
    KEY idx_listings_plant (plant_id),
    KEY idx_listings_species (species_id),
    KEY idx_listings_town (town_id),
    KEY idx_listings_exchanged_with (exchanged_with),
    -- The Exchange tab lists open listings, newest first.
    KEY idx_listings_status_created (status, created_at),
    CONSTRAINT chk_listings_km CHECK (km_from_town BETWEEN 0 AND 30),
    CONSTRAINT fk_listings_user           FOREIGN KEY (user_id)        REFERENCES users (id)         ON DELETE CASCADE,
    -- SET NULL: the advert stays when the owner deletes the plant; species_id keeps saying what it offers (DES-07).
    CONSTRAINT fk_listings_plant          FOREIGN KEY (plant_id)       REFERENCES plants (id)        ON DELETE SET NULL,
    CONSTRAINT fk_listings_species        FOREIGN KEY (species_id)     REFERENCES plant_species (id) ON DELETE SET NULL,
    CONSTRAINT fk_listings_town           FOREIGN KEY (town_id)        REFERENCES towns (id)         ON DELETE RESTRICT,
    CONSTRAINT fk_listings_exchanged_with FOREIGN KEY (exchanged_with) REFERENCES users (id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 14. listing_photos: extra photos for the Listing Detail gallery
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE listing_photos (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id INT UNSIGNED NOT NULL,
    path       VARCHAR(255) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_listing_photos_listing (listing_id),
    CONSTRAINT fk_listing_photos_listing FOREIGN KEY (listing_id) REFERENCES exchange_listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 15. listing_interests: "I'm interested" requests, one per member per listing
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE listing_interests (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    message    VARCHAR(500) NULL DEFAULT NULL,
    status     ENUM('new','seen','withdrawn') NOT NULL DEFAULT 'new',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_interests_listing_user (listing_id, user_id),
    KEY idx_interests_user (user_id),
    CONSTRAINT fk_interests_listing FOREIGN KEY (listing_id) REFERENCES exchange_listings (id) ON DELETE CASCADE,
    CONSTRAINT fk_interests_user    FOREIGN KEY (user_id)    REFERENCES users (id)             ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 16. user_ratings: trust ratings after a finished exchange
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE user_ratings (
    id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    rater_id   INT UNSIGNED     NULL DEFAULT NULL,
    rated_id   INT UNSIGNED     NOT NULL,
    listing_id INT UNSIGNED     NULL DEFAULT NULL,
    score      TINYINT UNSIGNED NOT NULL,
    comment    VARCHAR(500)     NULL DEFAULT NULL,
    created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One rating per member per exchange. Rows with a NULL rater or listing never clash (NULL is not equal to NULL).
    UNIQUE KEY uq_ratings_rater_listing (rater_id, listing_id),
    KEY idx_ratings_rated (rated_id),
    KEY idx_ratings_listing (listing_id),
    CONSTRAINT chk_ratings_score CHECK (score BETWEEN 1 AND 5),
    -- SET NULL (DES-07): a review stays when its writer leaves ("Former member") or the listing is deleted.
    CONSTRAINT fk_ratings_rater   FOREIGN KEY (rater_id)   REFERENCES users (id)             ON DELETE SET NULL,
    CONSTRAINT fk_ratings_rated   FOREIGN KEY (rated_id)   REFERENCES users (id)             ON DELETE CASCADE,
    CONSTRAINT fk_ratings_listing FOREIGN KEY (listing_id) REFERENCES exchange_listings (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 17. listing_categories: the categories a listing is filed under (many-to-many with a fixed list)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE listing_categories (
    listing_id INT UNSIGNED NOT NULL,
    category   ENUM('indoor','outdoor','succulents','air_purifying','flowering','edible','pet_friendly') NOT NULL,
    -- No id column: the pair is the key, so a listing cannot have the same category twice.
    PRIMARY KEY (listing_id, category),
    KEY idx_listing_categories_category (category),
    CONSTRAINT fk_listing_categories_listing FOREIGN KEY (listing_id) REFERENCES exchange_listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 18. care_guides: cache of AI care guides, one per normalised species name
-- No foreign key, so a typed custom_species can have a guide too.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE care_guides (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    species    VARCHAR(150) NOT NULL,
    guide_json JSON         NOT NULL,
    model_used VARCHAR(50)  NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_care_guides_species (species)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
