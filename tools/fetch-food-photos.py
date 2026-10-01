#!/usr/bin/env python3
"""
Fetch real, properly-licensed food photographs from Wikimedia Commons
for each dish on the campus canteen menu.

Why Wikimedia Commons: every file carries machine-readable license and
author metadata, so the demo menu ships with correct attribution instead
of mystery images scraped from a search engine.

Ranking policy (learned the hard way):
  Relevance comes FIRST — Wikimedia's own search ranking is the primary
  signal. License quality is only a tie-breaker, because preferring
  libre licences greedily pulls in a prolific CC0 uploader's bulk
  uploads rather than a photo of the actual dish.

Outputs:
  public/assets/menu/<slug>.jpg        the photo (800px wide)
  public/assets/menu/credits.json      attribution manifest

Usage:
  python3 tools/fetch-food-photos.py              # fill in what's missing
  python3 tools/fetch-food-photos.py --force      # re-download everything
  python3 tools/fetch-food-photos.py --only turon # one dish
  python3 tools/fetch-food-photos.py --list       # show candidates, don't download
"""

import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

API = "https://commons.wikimedia.org/w/api.php"
UA = "CampusFoodDemo/1.0 (student coursework project; educational use)"

OUT_DIR = "/home/user/campus-food/public/assets/menu"

# dish slug -> ordered list of Commons search queries, most specific first
DISHES = {
    "chicken-adobo-rice":  ["chicken adobo", "adobong manok"],
    "pork-sisig-rice":     ["sisig"],
    "beef-caldereta-rice": ["beef caldereta", "caldereta"],
    "fried-bangus-rice":   ["daing na bangus", "fried milkfish", "bangus"],
    "lechon-kawali-rice":  ["lechon kawali", "crispy pork belly"],
    "veggie-lumpia-rice":  ["lumpiang gulay", "vegetable lumpia", "lumpia"],
    "pancit-canton":       ["pancit canton"],
    "la-paz-batchoy":      ["batchoy"],
    "beef-mami":           ["beef mami", "mami soup"],
    "creamy-sopas":        ["sopas", "macaroni soup", "chicken macaroni soup"],
    "siopao-asado":        ["siopao", "steamed pork bun"],
    "cheesy-nachos":       ["nachos"],
    "french-fries":        ["french fries"],
    "turon":               ["turon", "banana spring roll"],
    "fish-balls":          ["fishball", "fish ball"],
    "pandesal":            ["pandesal"],
    "ensaymada":           ["ensaymada", "ensaimada"],
    "cream-puff":          ["cream puff"],
    "iced-tea":            ["iced tea"],
    "gulaman-sago":        ["sago gulaman", "gulaman"],
    "fresh-buko-juice":    ["buko juice", "coconut water"],
    "hot-coffee":          ["coffee cup", "black coffee"],
    "softdrinks":          ["soft drink can", "cola can"],
    "bottled-water":       ["glass of water"],
    "halo-halo":           ["halo-halo", "halo halo dessert"],
    "leche-flan":          ["leche flan"],
    "mango-graham":        ["mango float", "mango graham"],
}

# After eyeballing the results, pin the exact Commons file to use.
# Anything listed here wins over the automatic pick.
MANUAL_PICK = {
    # Commons genuinely has no clean, unbranded bottle-of-water photo —
    # every candidate was a product shot, a truck, or a plastic-waste pile.
    # The menu item is therefore named "Purified Water", which this glass
    # of water honestly represents.
    "bottled-water": "File:Fasting 4-Fasting-a-glass-of-water-on-an-empty-plate.jpg",

    # Automatic search kept returning branded cans (Canada Dry, Coca-Cola).
    # A generic glass of cola is both unbranded and more appetising.
    "softdrinks": "File:Glass cola.jpg",

    # Forum/blogger photos of turon are unappetising close-ups. This is the
    # photo Commons itself uses for the turon article.
    "turon": "File:Turon.jpg",
}

LICENSE_ORDER = [
    (r"^cc0|\bcc0\b|public domain|\bpd\b", 20),
    (r"cc[ -]?by[ -]?[234](\.\d)?(?![- ]?sa)", 16),
    (r"cc[ -]?by", 12),
    (r"cc[ -]?by[ -]?sa", 10),
    (r"gfdl", 4),
]
REJECT_LICENSE = re.compile(r"non[- ]?free|fair use|all rights reserved", re.I)
# Titles that are usually a bulk upload, a diagram, or an artwork rather than
# a straightforward photo of the dish.
REJECT_TITLE = re.compile(
    r"\b(logo|coat of arms|map|diagram|chart|poster|stamp|signboard|"
    r"menu|nutrition|label|packaging|package|advert|billboard|"
    # scene photos: a canteen or a market is not the dish itself
    r"vendor|stall|market|shop|store|street|road|festival|parade|"
    r"truck|van|delivery|factory|plant|machine|warehouse|portrait|"
    r"monument|statue|building|panorama|"
    # brands: menus shouldn't advertise other people's products
    r"coca|pepsi|sprite|fanta|canada dry|kirkland|nestle|"
    r"starbucks|mcdonald|jollibee)\b", re.I)


def api(params: dict, tries: int = 4) -> dict:
    params = {**params, "format": "json", "formatversion": "2"}
    url = API + "?" + urllib.parse.urlencode(params)

    for attempt in range(tries):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=45) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as exc:
            if exc.code == 429 and attempt < tries - 1:
                wait = 5 * (2 ** attempt)          # 5s, 10s, 20s
                print(f"      rate limited, waiting {wait}s…", file=sys.stderr)
                time.sleep(wait)
                continue
            raise
        except Exception:
            if attempt < tries - 1:
                time.sleep(3)
                continue
            raise
    return {}


def strip_html(text: str) -> str:
    text = re.sub(r"<[^>]+>", "", text or "")
    return re.sub(r"\s+", " ", text).strip()


def license_score(name: str) -> int:
    if not name:
        return 0
    if REJECT_LICENSE.search(name):
        return -1
    for pattern, score in LICENSE_ORDER:
        if re.search(pattern, name.lower()):
            return score
    return 5                                     # unknown but not obviously bad


def search(query: str, limit: int = 15, phrase: bool = True) -> list:
    term = f'"{query}"' if phrase else query
    data = api({
        "action": "query",
        "generator": "search",
        "gsrsearch": f"filetype:bitmap {term}",
        "gsrnamespace": "6",
        "gsrlimit": str(limit),
        "prop": "imageinfo",
        "iiprop": "url|extmetadata|size|mime",
        "iiurlwidth": "800",
    })
    pages = data.get("query", {}).get("pages", []) or []
    # generator=search preserves relevance in the "index" field
    return sorted(pages, key=lambda p: p.get("index", 999))


def candidate_info(page: dict, rank: int, query: str) -> dict | None:
    title = page.get("title", "")
    if REJECT_TITLE.search(title):
        return None

    # The query word must appear in the filename somewhere. Without this,
    # CirrusSearch happily returns files that merely mention the word in
    # their description or categories.
    significant = [w for w in re.split(r"[^a-z]+", query.lower()) if len(w) > 3]
    if significant and not any(w in title.lower() for w in significant):
        return None

    infos = page.get("imageinfo") or []
    if not infos:
        return None
    info = infos[0]

    mime = info.get("mime", "")
    if "image" not in mime or mime.endswith("svg+xml"):
        return None

    width, height = info.get("width", 0), info.get("height", 0)
    if width < 480 or height < 320:
        return None

    meta = info.get("extmetadata", {})
    lic = strip_html((meta.get("LicenseShortName", {}) or {}).get("value", ""))
    lic_score = license_score(lic)
    if lic_score < 0:
        return None

    # ---- scoring: relevance dominates ----------------------------------
    score = max(0, 100 - rank * 6)               # Wikimedia's own ranking
    score += lic_score // 2                      # licence is a tie-breaker
    ratio = (width / height) if height else 0
    if 1.05 <= ratio <= 1.9:
        score += 12                              # landscape suits the cards
    elif ratio >= 0.85:
        score += 4

    # Title similarity to the query
    qwords = [w for w in re.split(r"[^a-z]+", query.lower()) if len(w) > 3]
    score += sum(6 for w in qwords if w in title.lower())

    return {
        "score": score,
        "rank": rank,
        "title": title,
        "thumburl": info.get("thumburl") or info.get("url"),
        "descurl": info.get("descriptionurl", ""),
        "license": lic,
        "author": strip_html((meta.get("Artist", {}) or {}).get("value", "")),
        "width": width,
        "height": height,
    }


def rank_candidates(pages: list, query: str, used: set) -> list:
    out = []
    for i, page in enumerate(pages):
        if page.get("title") in used:
            continue
        info = candidate_info(page, i, query)
        if info:
            out.append(info)
    out.sort(key=lambda c: c["score"], reverse=True)
    return out


def download(url: str, path: str) -> int:
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    for attempt in range(4):
        try:
            with urllib.request.urlopen(req, timeout=90) as resp:
                data = resp.read()
            with open(path, "wb") as fh:
                fh.write(data)
            return len(data)
        except urllib.error.HTTPError as exc:
            if exc.code == 429 and attempt < 3:
                time.sleep(6 * (attempt + 1))
                continue
            raise
    raise RuntimeError("download kept failing")


def find_file_by_title(title: str) -> dict | None:
    data = api({
        "action": "query",
        "titles": title,
        "prop": "imageinfo",
        "iiprop": "url|extmetadata|size|mime",
        "iiurlwidth": "800",
    })
    pages = data.get("query", {}).get("pages", []) or []
    if not pages:
        return None
    info = candidate_info(pages[0], 0, title)
    if info:
        info["query"] = "(manual pick)"
    return info


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--force", action="store_true", help="re-download existing photos")
    ap.add_argument("--only", nargs="*", help="limit to these slugs")
    ap.add_argument("--list", action="store_true", help="show candidates only")
    args = ap.parse_args()

    os.makedirs(OUT_DIR, exist_ok=True)
    credits_path = os.path.join(OUT_DIR, "credits.json")
    credits = {}
    if os.path.exists(credits_path):
        with open(credits_path) as fh:
            credits = json.load(fh)

    slugs = args.only or list(DISHES)
    used = {c["title"] for c in credits.values() if "title" in c}
    problems = []

    for slug in slugs:
        if slug not in DISHES:
            print(f"  ? unknown slug {slug}")
            continue

        dest = os.path.join(OUT_DIR, f"{slug}.jpg")
        if os.path.exists(dest) and not args.force and not args.list:
            print(f"  = {slug:22s} kept (exists)")
            continue

        # ---- a manual pick short-circuits the search --------------------
        chosen = None
        if slug in MANUAL_PICK:
            chosen = find_file_by_title(MANUAL_PICK[slug])
            if chosen:
                print(f"  ★ {slug:22s} using manual pick")
            time.sleep(1.5)

        # ---- otherwise search -------------------------------------------
        if chosen is None:
            tried = []
            for query in DISHES[slug]:
                tried.append(query)
                cands = []
                # Phrase search first for precision, then loosen it up.
                for phrase in (True, False):
                    try:
                        pages = search(query, phrase=phrase)
                    except Exception as exc:          # noqa: BLE001
                        print(f"  ! {slug:22s} search error: {exc}")
                        continue
                    cands = rank_candidates(pages, query, used)
                    if cands:
                        break

                if args.list:
                    print(f"\n  {slug}  (query: {query})")
                    for c in cands[:5]:
                        print(f"      {c['score']:4d}  {c['license']:14s} "
                              f"{c['width']}x{c['height']}  {c['title'][5:60]}")
                    break

                if cands:
                    chosen = cands[0]
                    chosen["query"] = query
                    if len(cands) > 1:
                        chosen["runner_up"] = cands[1]["title"]
                    break
                time.sleep(1.5)

        if args.list:
            continue

        if not chosen:
            problems.append(slug)
            print(f"  ✗ {slug:22s} no usable photo")
            continue

        try:
            size = download(chosen["thumburl"], dest)
        except Exception as exc:                      # noqa: BLE001
            problems.append(slug)
            print(f"  ✗ {slug:22s} download failed: {exc}")
            continue

        used.add(chosen["title"])
        credits[slug] = {
            "file": f"assets/menu/{slug}.jpg",
            "source": "Wikimedia Commons",
            "title": chosen["title"],
            "author": chosen["author"] or "Unknown — see file page",
            "license": chosen["license"] or "See file page",
            "page": chosen["descurl"],
            "sequence": f"score {chosen['score']}",
        }
        print(f"  ✓ {slug:22s} {size/1024:5.0f} KB  {chosen['license']:14s} "
              f"{chosen['title'][5:58]}")
        time.sleep(1.5)                                # be polite to the API

    if not args.list:
        with open(credits_path, "w") as fh:
            json.dump(credits, fh, indent=2)
        print(f"\nCollected {len(credits)}/{len(DISHES)} photos")
        if problems:
            print(f"Still missing: {', '.join(problems)}")


if __name__ == "__main__":
    main()
