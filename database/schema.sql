-- =============================================================
--  Campus Food System  --  Database Schema
--  Target: MariaDB 10.4+ / MySQL 5.7+
--  Charset: utf8mb4 (supports emoji + full Unicode)
--
--  This file creates an EMPTY database: no users, no menu, no
--  images. Production is set up through the admin pages.
--  demo-data.sql adds the sample canteen, including real
--  photographs, for demonstrations only.
-- =============================================================

DROP DATABASE IF EXISTS campus_food;
CREATE DATABASE campus_food
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE campus_food;

-- -------------------------------------------------------------
--  users : students and canteen admins
-- -------------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100)  NOT NULL,
    email         VARCHAR(150)  NOT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    student_id    VARCHAR(30)   DEFAULT NULL,     -- e.g. "2023-00123"
    phone         VARCHAR(20)   DEFAULT NULL,
    role          ENUM('student','admin') NOT NULL DEFAULT 'student',
    wallet        DECIMAL(10,2) NOT NULL DEFAULT 0.00,  -- campus e-wallet balance
    is_demo       TINYINT(1)    NOT NULL DEFAULT 0,      -- 1 = sample data row
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
--  categories : menu groupings (Rice Meals, Drinks, ...)
-- -------------------------------------------------------------
CREATE TABLE categories (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(60) NOT NULL,
    -- An icon NAME from includes/icons.php ('rice', 'noodles', 'drink').
    -- Never an emoji: the app draws these as inline SVG. VARCHAR(8) is kept
    -- from the original design and fits every name in that file.
    icon       VARCHAR(8)  NOT NULL DEFAULT 'plate',
    sort_order INT         NOT NULL DEFAULT 0,
    is_active  TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
--  menu_items : the food catalogue
-- -------------------------------------------------------------
CREATE TABLE menu_items (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id   INT UNSIGNED NOT NULL,
    name          VARCHAR(120)  NOT NULL,
    description   VARCHAR(255)  DEFAULT NULL,
    price         DECIMAL(10,2) NOT NULL,
    icon          VARCHAR(8)    NOT NULL DEFAULT 'plate',   -- see includes/icons.php

    -- Path to a real photograph, relative to public/. NULL means "no photo
    -- yet". Only demo-data.sql ever sets this: production menus are built
    -- through the admin pages and start without images, so nothing that
    -- ships as sample content can end up on a real menu by accident.
    image_path    VARCHAR(255)  DEFAULT NULL,

    -- Photo attribution, required by the CC BY / CC BY-SA licences the demo
    -- photographs are published under. Empty for photos a canteen uploads
    -- itself, since those are its own.
    image_credit  VARCHAR(255)  DEFAULT NULL,
    image_source  VARCHAR(255)  DEFAULT NULL,

    stock         INT           NOT NULL DEFAULT 0,
    prep_minutes  SMALLINT      NOT NULL DEFAULT 10,
    is_available  TINYINT(1)    NOT NULL DEFAULT 1,
    is_demo       TINYINT(1)    NOT NULL DEFAULT 0,   -- 1 = sample data row
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_menu_cat (category_id),
    KEY idx_menu_avail (is_available),
    CONSTRAINT fk_menu_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
--  orders : one row per placed order (the "ticket")
-- -------------------------------------------------------------
CREATE TABLE orders (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_code     VARCHAR(20)  NOT NULL,          -- CF-000001
    user_id        INT UNSIGNED NOT NULL,
    status         ENUM('pending','preparing','ready','completed','cancelled')
                   NOT NULL DEFAULT 'pending',
    payment_method ENUM('cash','wallet') NOT NULL DEFAULT 'cash',
    pickup_slot    VARCHAR(30)  NOT NULL DEFAULT 'ASAP',
    notes          VARCHAR(255) DEFAULT NULL,
    item_count     INT          NOT NULL DEFAULT 0,
    subtotal       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    service_fee    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    placed_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_code (order_code),
    KEY idx_orders_user (user_id),
    KEY idx_orders_status (status),
    KEY idx_orders_placed (placed_at),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
--  order_items : line items, with a price snapshot
--  (name/price are copied so old receipts never change when
--   the canteen later edits a menu price)
-- -------------------------------------------------------------
CREATE TABLE order_items (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id     INT UNSIGNED NOT NULL,
    menu_item_id INT UNSIGNED DEFAULT NULL,
    item_name    VARCHAR(120)  NOT NULL,
    item_icon    VARCHAR(8)    NOT NULL DEFAULT 'plate',    -- icon name, snapshotted
    unit_price   DECIMAL(10,2) NOT NULL,
    quantity     INT           NOT NULL DEFAULT 1,
    line_total   DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_oi_order (order_id),
    KEY idx_oi_item (menu_item_id),
    CONSTRAINT fk_oi_order FOREIGN KEY (order_id)
        REFERENCES orders (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_oi_item FOREIGN KEY (menu_item_id)
        REFERENCES menu_items (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
--  login_attempts — every failed sign-in, for throttling
--
--  Nothing here is a password: just which email was tried, from which
--  address, and when. Rows older than a day are deleted by the app itself
--  (see login_record_failure() in includes/security.php), so the table
--  stays small and holds no long-term history of anyone's typing.
-- -------------------------------------------------------------
CREATE TABLE login_attempts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email        VARCHAR(150) NOT NULL,
    ip           VARCHAR(45)  NOT NULL,          -- fits IPv6
    attempted_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_la_email (email, attempted_at),
    KEY idx_la_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
--  A handy view for the admin dashboard
-- -------------------------------------------------------------
CREATE OR REPLACE VIEW v_daily_sales AS
SELECT DATE(placed_at)                                  AS sales_date,
       COUNT(*)                                         AS order_count,
       SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0 END) AS gross_sales
FROM orders
GROUP BY DATE(placed_at)
ORDER BY sales_date DESC;
