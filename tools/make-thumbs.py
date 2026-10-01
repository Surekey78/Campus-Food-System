#!/usr/bin/env python3
"""
Generate the smaller photo sizes the menu actually displays.

The menu card shows a photo about 250px wide. Serving the full 900px
original there means a phone on mobile data downloads roughly 3 MB to
render one screen of food. Two smaller sizes fix that:

    <slug>.jpg          full size, for the dish's own view
    thumbs/<slug>.jpg   512px wide — the menu grid
    thumbs/tiny/<slug>.jpg  160px wide — admin list rows

PHP falls back to the full-size file whenever a thumbnail is missing,
so the app works fine before this has ever been run.

Run:  python3 tools/make-thumbs.py
      python3 tools/make-thumbs.py --force     # rebuild existing thumbs
"""

import argparse
import os
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Every folder holding menu photos, and the sizes it needs.
FOLDERS = [
    os.path.join(ROOT, "public/assets/menu"),      # demo photographs
    os.path.join(ROOT, "public/uploads/menu"),     # photos a canteen uploaded
]

SIZES = {
    "thumbs": 512,          # menu cards, comfortable at 2x pixel density
    "thumbs/tiny": 160,     # admin table thumbnails
}

QUALITY = 80


def build(source: str, dest: str, width: int) -> int:
    """Write one resized copy. Returns the new file size in bytes."""
    os.makedirs(os.path.dirname(dest), exist_ok=True)

    with Image.open(source) as im:
        im = im.convert("RGB")
        if im.width > width:
            height = round(im.height * (width / im.width))
            im = im.resize((width, height), Image.LANCZOS)
        im.save(dest, "JPEG", quality=QUALITY, optimize=True, progressive=True)

    return os.path.getsize(dest)


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--force", action="store_true", help="rebuild thumbs that already exist")
    args = ap.parse_args()

    total_before = total_after = 0
    made = skipped = 0

    for folder in FOLDERS:
        if not os.path.isdir(folder):
            continue

        images = sorted(
            f for f in os.listdir(folder)
            if f.lower().endswith((".jpg", ".jpeg", ".png", ".webp"))
        )
        if not images:
            continue

        print(f"\n{folder.replace(ROOT + '/', '')}  ({len(images)} photo(s))")

        for name in images:
            src = os.path.join(folder, name)
            base = os.path.splitext(name)[0]
            total_before += os.path.getsize(src)

            results = []
            for sub, width in SIZES.items():
                dest = os.path.join(folder, sub, f"{base}.jpg")
                existed = os.path.exists(dest) and not args.force

                if existed:
                    results.append("kept")
                    skipped += 1
                else:
                    size = build(src, dest, width)
                    results.append(f"{width}px {size // 1024}KB")
                    made += 1

            print(f"  {base[:34]:34s} {', '.join(results)}")

    print(f"\n{made} thumbnail(s) written, {skipped} already present")

    # Report the saving on the menu grid, which is what students load.
    for sub, width in SIZES.items():
        folder = os.path.join(ROOT, "public/assets/menu", sub)
        if not os.path.isdir(folder):
            continue
        files = [f for f in os.listdir(folder) if f.endswith(".jpg")]
        if not files:
            continue
        size = sum(os.path.getsize(os.path.join(folder, f)) for f in files)
        print(f"  {len(files)} files at {width}px total {size / 1024 / 1024:.1f} MB "
              f"(about {size / len(files) / 1024:.0f} KB each)")


if __name__ == "__main__":
    main()
