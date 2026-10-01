#!/usr/bin/env python3
"""
Generate database/demo-data.sql from the photo manifest.

Keeping this as a script means the dish names, image paths and photo
attribution can never drift apart: the credits come straight out of
public/assets/menu/credits.json, which tools/fetch-food-photos.py wrote
from Wikimedia Commons metadata.

Run:  python3 tools/build-demo-data.py
"""

import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CREDITS = os.path.join(ROOT, "public/assets/menu/credits.json")
OUT = os.path.join(ROOT, "database/demo-data.sql")

# category id, display name, [ (dish name, description, price, icon, stock, prep_minutes) ]
CATEGORIES = [
    (1, "Rice Meals", [
        ("Chicken Adobo Rice",  "Slow-braised chicken in soy, vinegar and garlic with steamed rice.", 75.00, "meat", 25, 10),
        ("Pork Sisig Rice",     "Sizzling chopped pork with calamansi, chili and egg on rice.",       85.00, "bowl", 18, 12),
        ("Beef Caldereta Rice", "Tender beef in tomato-pepper stew with potatoes and carrots.",       95.00, "soup", 12, 15),
        ("Fried Bangus Rice",   "Crispy milkfish with garlic rice and vinegar dip.",                  80.00, "fish", 15, 12),
        ("Lechon Kawali Rice",  "Deep-fried crispy pork belly with liver sauce.",                     90.00, "plate", 10, 14),
        ("Veggie Lumpia Rice",  "Vegetable spring rolls with sweet chili sauce and rice.",            65.00, "roll", 20,  8),
    ]),
    (2, "Noodles & Soup", [
        ("Pancit Canton",  "Stir-fried egg noodles with pork, shrimp and vegetables.",       45.00, "noodles", 30,  8),
        ("La Paz Batchoy", "Iloilo-style noodle soup with pork, liver and chicharon.",       60.00, "soup", 20, 10),
        ("Beef Mami",      "Hot beef noodle soup with scallions and fried garlic.",          65.00, "noodles", 18, 12),
        ("Creamy Sopas",   "Milky macaroni soup with chicken and vegetables.",               50.00, "soup", 22,  8),
    ]),
    (3, "Snacks", [
        ("Siopao Asado",       "Steamed bun filled with sweet-savoury pork asado.",           35.00, "dumpling", 40, 3),
        ("Cheesy Nachos",      "Corn chips with cheese sauce, salsa and jalapenos.",          55.00, "cheese", 16, 6),
        ("French Fries",       "Shoestring fries with cheese powder and ketchup.",            45.00, "snack", 25, 6),
        ("Turon (2 pcs)",      "Crispy banana spring rolls with jackfruit and caramel.",      20.00, "roll", 35, 4),
        ("Fish Balls (6 pcs)", "Street-style fish balls with sweet and spicy sauce.",         25.00, "fish", 30, 5),
    ]),
    (4, "Bread & Pastries", [
        ("Pandesal (3 pcs)", "Freshly baked Filipino bread rolls, served warm.",                  8.00, "bread", 80, 2),
        ("Ensaymada",        "Soft brioche-style bun topped with buttercream and cheese.",       25.00, "bread", 24, 2),
        ("Cream Puff",       "Choux pastry filled with vanilla custard.",                        30.00, "cake", 20, 2),
    ]),
    (5, "Drinks", [
        ("Iced Tea",           "House-brewed sweet iced tea over crushed ice.",                      25.00, "drink", 60, 2),
        ("Gulaman at Sago",    "Brown sugar drink with agar jelly and tapioca pearls.",              20.00, "drink", 55, 3),
        ("Fresh Buko Juice",   "Chilled young coconut juice with strips of coconut meat.",           35.00, "fruit", 20, 4),
        ("Hot Coffee",         "Brewed black coffee, sugar and creamer on the side.",                30.00, "coffee", 40, 3),
        ("Softdrinks (in can)","Cola, served chilled over ice with a slice of lemon.",               30.00, "can", 48, 1),
        ("Purified Water",     "Chilled drinking water, refilled at the canteen station.",           15.00, "water", 100, 1),
    ]),
    (6, "Desserts", [
        ("Halo-Halo",    "Shaved ice with mixed sweets, ube halaya and leche flan.",        70.00, "dessert", 12, 8),
        ("Leche Flan",   "Rich steamed caramel custard, single slice.",                     35.00, "cake", 18, 2),
        ("Mango Graham", "Layered graham, cream and ripe mango cups.",                      45.00, "fruit", 15, 3),
    ]),
]

# dish name (menu) -> photo slug (assets/menu/<slug>.jpg)
NAME_TO_SLUG = {
    "Chicken Adobo Rice":  "chicken-adobo-rice",
    "Pork Sisig Rice":     "pork-sisig-rice",
    "Beef Caldereta Rice": "beef-caldereta-rice",
    "Fried Bangus Rice":   "fried-bangus-rice",
    "Lechon Kawali Rice":  "lechon-kawali-rice",
    "Veggie Lumpia Rice":  "veggie-lumpia-rice",
    "Pancit Canton":       "pancit-canton",
    "La Paz Batchoy":      "la-paz-batchoy",
    "Beef Mami":           "beef-mami",
    "Creamy Sopas":        "creamy-sopas",
    "Siopao Asado":        "siopao-asado",
    "Cheesy Nachos":       "cheesy-nachos",
    "French Fries":        "french-fries",
    "Turon (2 pcs)":       "turon",
    "Fish Balls (6 pcs)":  "fish-balls",
    "Pandesal (3 pcs)":    "pandesal",
    "Ensaymada":           "ensaymada",
    "Cream Puff":          "cream-puff",
    "Iced Tea":            "iced-tea",
    "Gulaman at Sago":     "gulaman-sago",
    "Fresh Buko Juice":    "fresh-buko-juice",
    "Hot Coffee":          "hot-coffee",
    "Softdrinks (in can)": "softdrinks",
    "Purified Water":      "bottled-water",
    "Halo-Halo":           "halo-halo",
    "Leche Flan":          "leche-flan",
    "Mango Graham":        "mango-graham",
}

# Bcrypt hashes for the demo passwords (admin123 and campus123).
ADMIN_HASH = "$2y$12$kzSHUb16PkoRA15CX31Gzuw6Jf38hWCoj03WRRHPbairuAt6kbtvm"
STUDENT_HASH = "$2y$12$PgdcoiaIPz.cfuepHuWHYuSjcyHBlMK9apZ0i.eJlsIs13Q4zJ7uq"


def q(value):
    """Quote a value for SQL, or NULL."""
    if value is None:
        return "NULL"
    text = str(value).replace("\\", "\\\\").replace("'", "''")
    return f"'{text}'"


def credit_for(slug):
    """(attribution, source page) for a photo, trimmed to fit the columns."""
    entry = CREDITS_DATA.get(slug)
    if not entry:
        return None, None
    author = re.sub(r"\s+", " ", entry.get("author") or "Unknown").strip()
    # Some Commons author fields are long HTML-ish credit lines.
    author = re.sub(r"^(User:|Photo by|By)\s*", "", author, flags=re.I)
    if len(author) > 70:
        author = author[:67] + "…"
    return f"{author} / {entry.get('license', 'see source')}", entry.get("page")


if not os.path.exists(CREDITS):
    raise SystemExit(
        f"Missing {CREDITS}.\n"
        "Run tools/fetch-food-photos.py first to download the demo photos."
    )

with open(CREDITS) as fh:
    CREDITS_DATA = json.load(fh)

# ---------------------------------------------------------------- header
parts = [f"""-- =============================================================
--  Campus Food System  --  DEMO DATA  (not for production)
-- =============================================================
--  Adds a sample canteen so the app can be demonstrated: three
--  accounts, six categories, 27 menu items and four past orders.
--
--  Every row is flagged is_demo = 1. That is the marker the
--  "Remove demo data" button in Admin > Menu items looks for, so
--  you can clear the samples out in one click before going live.
--
--  Run AFTER schema.sql:
--      mariadb campus_food < database/schema.sql
--      mariadb campus_food < database/demo-data.sql
--
--  ------------------------------------------------------------------
--  ABOUT THE PHOTOGRAPHS
--  ------------------------------------------------------------------
--  The 27 dishes carry REAL PHOTOGRAPHS, not AI-generated images.
--  Each one is a genuine photo of that dish, sourced from Wikimedia
--  Commons under CC0, public domain, CC BY or CC BY-SA.
--
--  image_credit holds "author / licence" and image_source holds the
--  Commons file page, because CC BY and CC BY-SA both require
--  attribution. Those columns are shown next to the dish on the menu
--  and in the admin editor.
--
--  Full manifest:  public/assets/menu/credits.json
--  How they were fetched and vetted:  tools/fetch-food-photos.py
--
--  DO NOT use this file for a real canteen:
--    * the photos show generic Filipino dishes cooked elsewhere, not
--      this canteen's actual food — misleading to paying students;
--    * the accounts below share publicly known passwords.
--  Build a real menu through the admin pages instead: those start
--  with no images, and the canteen photographs its own cooking.
--
--  Demo logins (change or delete before going live):
--    admin@campus.edu  / admin123    (canteen admin)
--    maria@campus.edu  / campus123   (student)
--    juan@campus.edu   / campus123   (student)
-- =============================================================

USE campus_food;

-- ------------------------- users -----------------------------
INSERT INTO users (name, email, password_hash, student_id, phone, role, wallet, is_demo) VALUES
('Canteen Admin', 'admin@campus.edu', {q(ADMIN_HASH)},
 NULL, '0917-000-0001', 'admin', 0.00, 1),
('Maria Santos', 'maria@campus.edu', {q(STUDENT_HASH)},
 '2023-00123', '0917-123-4567', 'student', 500.00, 1),
('Juan Dela Cruz', 'juan@campus.edu', {q(STUDENT_HASH)},
 '2022-00456', '0918-765-4321', 'student', 250.00, 1);

-- ---------------------- categories ---------------------------
INSERT INTO categories (name, icon, sort_order) VALUES
('Rice Meals',       'rice', 1),
('Noodles & Soup',   'noodles', 2),
('Snacks',           'snack', 3),
('Bread & Pastries', 'bread', 4),
('Drinks',           'drink', 5),
('Desserts',         'dessert', 6);"""]

# ------------------------------------------------------------ menu items
rows = []
missing = []
for cat_id, cat_name, items in CATEGORIES:
    rows.append(f"-- {cat_name}  (category_id = {cat_id})")
    for name, desc, price, icon, stock, prep in items:
        slug = NAME_TO_SLUG.get(name)
        if not slug:
            missing.append(name)
            continue
        if slug not in CREDITS_DATA:
            missing.append(f"{name} (no photo credit for slug '{slug}')")
            continue
        note, page = credit_for(slug)
        rows.append(
            f"({cat_id}, {q(name)}, {q(desc)}, {price:.2f}, {q(icon)}, "
            f"{q('assets/menu/' + slug + '.jpg')}, {q(note)}, {q(page)}, "
            f"{stock}, {prep}, 1)," if False else
            f"({cat_id}, {q(name)}, {q(desc)}, {price:.2f}, {q(icon)}, "
            f"{q('assets/menu/' + slug + '.jpg')}, {q(note)}, {q(page)}, "
            f"{stock}, {prep}, 1),"
        )

rows[-1] = rows[-1].rstrip(",") + ";"        # last row ends the statement

parts.append("""
-- ---------------------- menu items ---------------------------
-- Column order: category_id, name, description, price, icon,
--               image_path, image_credit, image_source,
--               stock, prep_minutes, is_demo
--
-- image_path is relative to public/, so 'assets/menu/x.jpg' is served
-- as <site>/assets/menu/x.jpg.
INSERT INTO menu_items
    (category_id, name, description, price, icon,
     image_path, image_credit, image_source, stock, prep_minutes, is_demo) VALUES
""" + "\n".join(rows))

# --------------------------------------------------------------- orders
parts.append("""
-- ------------------- a few sample orders ---------------------
INSERT INTO orders (order_code, user_id, status, payment_method, pickup_slot, notes,
                    item_count, subtotal, service_fee, total, placed_at) VALUES
('CF-000001', 2, 'completed', 'wallet', '11:30 AM', 'Less rice please',
   2, 120.00, 5.00, 125.00, NOW() - INTERVAL 2 DAY),
('CF-000002', 3, 'completed', 'cash',   'ASAP', NULL,
   5, 175.00, 5.00, 180.00, NOW() - INTERVAL 1 DAY),
('CF-000003', 2, 'ready',     'cash',   '12:15 PM', 'Extra chili on the side',
   3, 145.00, 5.00, 150.00, NOW() - INTERVAL 3 HOUR),
('CF-000004', 3, 'pending',   'wallet', 'ASAP', NULL,
   1, 60.00, 5.00, 65.00, NOW() - INTERVAL 20 MINUTE);

-- order_items keeps its own copy of the name, icon and price, so these
-- rows stay correct even if the menu is edited or the item is deleted.
INSERT INTO order_items (order_id, menu_item_id, item_name, item_icon, unit_price, quantity, line_total) VALUES
(1, 1,  'Chicken Adobo Rice',  'meat', 75.00, 1, 75.00),
(1, 7,  'Pancit Canton',       'noodles', 45.00, 1, 45.00),
(2, 2,  'Pork Sisig Rice',     'bowl', 85.00, 1, 85.00),
(2, 19, 'Iced Tea',            'drink', 25.00, 2, 50.00),
(2, 15, 'Turon (2 pcs)',       'roll', 20.00, 2, 40.00),
(3, 3,  'Beef Caldereta Rice', 'soup', 95.00, 1, 95.00),
(3, 24, 'Purified Water',      'water', 15.00, 1, 15.00),
(3, 11, 'Siopao Asado',        'dumpling', 35.00, 1, 35.00),
(4, 8,  'La Paz Batchoy',      'soup', 60.00, 1, 60.00);
""")

with open(OUT, "w") as fh:
    fh.write("\n".join(parts))

dish_count = sum(len(items) for _, _, items in CATEGORIES)
print(f"Wrote {OUT}")
print(f"  {dish_count} menu items, 6 categories, 3 users, 4 orders")
if missing:
    print("  PROBLEMS:")
    for m in missing:
        print(f"    - {m}")
    raise SystemExit(1)
print("  all dishes have a photo + credit")
