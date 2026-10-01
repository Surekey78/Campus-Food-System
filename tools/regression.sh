#!/usr/bin/env bash
# =====================================================================
#  tools/regression.sh — prove the app still works after a change
# =====================================================================
#  Checks the behaviour that is easy to break by accident: cart maths,
#  stock and wallet bookkeeping, CSRF, the admin order flow, photo
#  uploads, and the sign-in throttle. Read-only pages are checked too,
#  because a stray PHP warning on any of them is a real bug.
#
#  It runs against a RUNNING server and it DOES move data: it places
#  orders, cancels them, edits an item and uploads a photo. Only run it
#  on the demo install. It refuses to run if the demo admin account is
#  missing, which is the usual sign you have pointed it at a real
#  canteen by mistake.
#
#      php -S 0.0.0.0:8000 -t public &
#      tools/regression.sh
#
#  Environment (same names the app uses):
#      BASE      default http://127.0.0.1:8000
#      DB_HOST DB_PORT DB_NAME DB_USER DB_PASS   as in config/db.php
#      MARIADB   client binary, default "mariadb"
#
#  Exit code 0 = everything passed.
# =====================================================================

set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8000}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-campus_food}"
DB_USER="${DB_USER:-campus}"
DB_PASS="${DB_PASS:-campus_pass}"
MARIADB="${MARIADB:-mariadb}"

ADMIN_EMAIL="admin@campus.edu";   ADMIN_PASS="admin123"
STUDENT_EMAIL="maria@campus.edu"; STUDENT_PASS="campus123"

# Where the app under test keeps its public folder. Normally this script
# sits inside the project it is testing, so that is the default — but
# tools/check-release.sh points it at an unpacked zip instead, and then a
# relative path would check the wrong files entirely.
PUBLIC_DIR="${PUBLIC_DIR:-$(cd "$(dirname "$0")/.." && pwd)/public}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASSED=0; FAILED=0
ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; PASSED=$((PASSED + 1)); }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$1"; FAILED=$((FAILED + 1)); }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }
is()   { if [ "$2" = "$3" ]; then ok "$1 ($2)"; else bad "$1 — expected '$3', got '$2'"; fi; }

db() { "$MARIADB" -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "-p$DB_PASS" \
       -N -B "$DB_NAME" -e "$1" 2>/dev/null; }

# ---------------------------------------------------------------- helpers
# A fresh cookie jar every time. A jar that is already logged in makes
# login.php answer with a 302 instead of a form, so a reused jar silently
# tests nothing at all.
csrf() {  # csrf <jar> <url>
    curl -s -c "$1" -b "$1" "$2" \
        | grep -o 'name="csrf" value="[^"]*"' | head -1 \
        | sed 's/.*value="//; s/"$//'
}

login() { # login <jar> <email> <pass>
    local jar="$1" email="$2" pass="$3" token
    rm -f "$jar"
    token="$(csrf "$jar" "$BASE/login.php")"
    if [ -z "$token" ]; then echo ""; return 1; fi
    curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' \
        --data-urlencode "csrf=$token" \
        --data-urlencode "email=$email" \
        --data-urlencode "password=$pass" \
        "$BASE/login.php"
}

# ---------------------------------------------------------- 0. reachable?
head_ "Server"

if ! curl -s -o /dev/null --max-time 5 "$BASE/index.php"; then
    bad "no answer from $BASE — start the PHP server first"
    printf '\n  Cannot continue.\n'; exit 1
fi
ok "answering on $BASE"

if [ "$(db "SELECT COUNT(*) FROM users WHERE email='$ADMIN_EMAIL'")" != "1" ]; then
    bad "demo admin ($ADMIN_EMAIL) not found — refusing to run against what may be a real canteen"
    printf '\n  This script places and cancels orders. Point it at the demo database.\n'
    exit 1
fi
ok "demo data present, safe to proceed"

# Remember what we are about to disturb, so the cleanup at the end can put
# the demo database back exactly as it was.
STUDENT_ID_EARLY="$(db "SELECT id FROM users WHERE email='$STUDENT_EMAIL'")"
WALLET_AT_START="$(db "SELECT wallet FROM users WHERE id=$STUDENT_ID_EARLY")"
STOCK_AT_START="$(db "SELECT stock FROM menu_items WHERE id=1")"

# ------------------------------------------------- 1. pages render cleanly
head_ "Pages render without PHP complaints"

# A user is not always required: index/cart/login/register are public.
for path in /index.php /cart.php /login.php /register.php; do
    body="$(curl -s "$BASE$path")"
    if printf '%s' "$body" | grep -qE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught'; then
        bad "$path contains a PHP error message"
        printf '%s' "$body" | grep -oE '(Fatal error|Warning|Notice|Deprecated|Uncaught)[^<]{0,80}' | head -1 | sed 's/^/      /'
    else
        ok "$path clean"
    fi
done

# ------------------------------------------------ 2. security headers + cookie
head_ "Security headers and session cookie"

hdrs="$(curl -s -D - -o /dev/null "$BASE/index.php")"
for h in "Content-Security-Policy" "X-Content-Type-Options" "X-Frame-Options" \
         "Referrer-Policy" "Permissions-Policy" "Cross-Origin-Opener-Policy"; do
    if printf '%s' "$hdrs" | grep -qi "^$h:"; then ok "$h"; else bad "$h missing"; fi
done

printf '%s' "$hdrs" | grep -qi 'content-security-policy:.*script-src .self.' \
    && ok "CSP keeps script-src 'self'" || bad "CSP script-src is not 'self' — inline script could run"

# Read the flags off the response header, not out of the cookie jar: a jar
# records only domain/path/expiry, so SameSite and HttpOnly are invisible there.
raw="$(curl -s -D - -o /dev/null "$BASE/login.php" | grep -i '^set-cookie:' || true)"
if [ -z "$raw" ]; then
    bad "no Set-Cookie header came back from /login.php"
else
    grep -qi 'campusfood_sid' <<<"$raw"  && ok "session cookie is named campusfood_sid" \
                                          || bad "unexpected session cookie name"
    grep -qi 'HttpOnly'      <<<"$raw"  && ok "cookie is HttpOnly" \
                                          || bad "cookie is readable by JavaScript"
    grep -qi 'SameSite=Lax'  <<<"$raw"  && ok "cookie is SameSite=Lax" \
                                          || bad "cookie has no SameSite protection"
fi

# ---------------------------------------------- 3. student cart → checkout
head_ "Ordering: cart maths, stock, wallet"

SJAR="$WORK/student.txt"
is "student sign-in" "$(login "$SJAR" "$STUDENT_EMAIL" "$STUDENT_PASS")" "302"

UID_="$(db "SELECT id FROM users WHERE email='$STUDENT_EMAIL'")"
P1="$(db "SELECT price FROM menu_items WHERE id=1")"
P19="$(db "SELECT price FROM menu_items WHERE id=19")"
FEE="$(php -r 'require "config/db.php"; echo SERVICE_FEE;' 2>/dev/null || echo 5)"
EXPECT="$(php -r "printf('%.2f', 2*$P1 + $P19 + $FEE);")"

# Wallet has to cover it, so top it up in SQL — crediting is an admin
# action with its own screen, and this script is not testing that screen.
db "UPDATE users SET wallet = 1000 WHERE id = $UID_"
STOCK_BEFORE="$(db "SELECT stock FROM menu_items WHERE id=1")"

token="$(csrf "$SJAR" "$BASE/index.php")"
for pair in "1:2" "19:1"; do
    id="${pair%%:*}"; qty="${pair##*:}"
    curl -s -b "$SJAR" -c "$SJAR" -o /dev/null \
        --data-urlencode "csrf=$token" --data-urlencode "item_id=$id" \
        --data-urlencode "qty=$qty" --data-urlencode "return_to=/index.php" \
        "$BASE/index.php"
done

cart_total="$(curl -s -b "$SJAR" "$BASE/cart.php" | grep -o '₱[0-9,]*\.[0-9][0-9]' | tail -1 | tr -d '₱,')"
is "cart total (2×item1 + 1×item19 + fee)" "$cart_total" "$EXPECT"

token="$(csrf "$SJAR" "$BASE/checkout.php")"
curl -s -b "$SJAR" -c "$SJAR" -o /dev/null -L \
    --data-urlencode "csrf=$token" --data-urlencode "pickup_slot=ASAP" \
    --data-urlencode "payment_method=wallet" --data-urlencode "notes=regression run" \
    "$BASE/checkout.php"

ORDER_ID="$(db "SELECT id FROM orders WHERE user_id=$UID_ ORDER BY id DESC LIMIT 1")"
ORDER_TOTAL="$(db "SELECT total FROM orders WHERE id=$ORDER_ID")"
is "order recorded" "$(db "SELECT status FROM orders WHERE id=$ORDER_ID")" "pending"
is "order total" "$ORDER_TOTAL" "$EXPECT"
is "stock decremented by 2" "$(db "SELECT stock FROM menu_items WHERE id=1")" "$((STOCK_BEFORE - 2))"
is "wallet debited by the total" "$(db "SELECT FORMAT(wallet,2) FROM users WHERE id=$UID_")" "$(php -r "printf('%.2f', 1000 - $EXPECT);" | sed 's/^/ /' | tr -d ' ')"
is "order lines written" "$(db "SELECT COUNT(*) FROM order_items WHERE order_id=$ORDER_ID")" "2"

# ------------------------------------------------------- 4. admin advances it
head_ "Admin order flow"

AJAR="$WORK/admin.txt"
is "admin sign-in" "$(login "$AJAR" "$ADMIN_EMAIL" "$ADMIN_PASS")" "302"

token="$(csrf "$AJAR" "$BASE/admin/index.php")"
curl -s -b "$AJAR" -c "$AJAR" -o /dev/null --data-urlencode "csrf=$token" \
    --data-urlencode "order_id=$ORDER_ID" --data-urlencode "to=preparing" \
    "$BASE/admin/index.php"
is "admin moved order to preparing" "$(db "SELECT status FROM orders WHERE id=$ORDER_ID")" "preparing"

# ------------------------------------------------------------- 5. CSRF refusal
head_ "CSRF protection"

code="$(curl -s -o /dev/null -w '%{http_code}' -b "$SJAR" \
        --data-urlencode "csrf=forged-token" --data-urlencode "order_id=$ORDER_ID" \
        "$BASE/orders.php")"
is "forged token rejected" "$code" "419"

# ------------------------------------------------ 6. cancel restores stock/wallet
head_ "Cancelling gives everything back"

# Cancelling is only allowed while an order is pending, so put it back.
db "UPDATE orders SET status='pending' WHERE id=$ORDER_ID"
WALLET_BEFORE_CANCEL="$(db "SELECT wallet FROM users WHERE id=$UID_")"
STOCK_BEFORE_CANCEL="$(db "SELECT stock FROM menu_items WHERE id=1")"

token="$(csrf "$SJAR" "$BASE/orders.php")"
curl -s -b "$SJAR" -c "$SJAR" -o /dev/null \
    --data-urlencode "csrf=$token" --data-urlencode "order_id=$ORDER_ID" \
    "$BASE/orders.php"

is "order cancelled" "$(db "SELECT status FROM orders WHERE id=$ORDER_ID")" "cancelled"
is "stock returned" "$(db "SELECT stock FROM menu_items WHERE id=1")" "$((STOCK_BEFORE_CANCEL + 2))"
is "wallet refunded" "$(db "SELECT wallet FROM users WHERE id=$UID_")" \
   "$(php -r "printf('%.2f', $WALLET_BEFORE_CANCEL + $ORDER_TOTAL);")"

# ------------------------------------------------------------ 7. photo upload
head_ "Photo upload"

# Post the edit form back exactly as the browser would, with a file
# attached. Filling the form from its own HTML means this keeps working
# when fields are added — it is the real form, not a guess at it.
UP_ITEM="$(db "SELECT id FROM menu_items WHERE is_demo=1 ORDER BY id LIMIT 1")"
EDIT_URL="$BASE/admin/items.php?edit=$UP_ITEM"
PLAN="$WORK/form.json"

curl -s -b "$AJAR" -c "$AJAR" "$EDIT_URL" > "$WORK/form.html"
php -r '
    $html = file_get_contents($argv[1]);
    $fields = [];
    if (preg_match_all("/<input\b[^>]*>/i", $html, $m)) {
        foreach ($m[0] as $tag) {
            if (!preg_match("/name=\"([^\"]+)\"/i", $tag, $n)) continue;
            if (preg_match("/type=\"(file|submit|button|checkbox|radio)\"/i", $tag)) continue;
            preg_match("/value=\"([^\"]*)\"/i", $tag, $v);
            $fields[$n[1]] = html_entity_decode($v[1] ?? "", ENT_QUOTES);
        }
    }
    if (preg_match_all("/<select\b[^>]*name=\"([^\"]+)\"[^>]*>(.*?)<\/select>/is", $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $sel) {
            if (preg_match("/value=\"([^\"]*)\"[^>]*selected/i", $sel[2], $v)) $fields[$sel[1]] = $v[1];
            elseif (preg_match("/<option[^>]*value=\"([^\"]*)\"/i", $sel[2], $v))  $fields[$sel[1]] = $v[1];
        }
    }
    if (preg_match("/<textarea\b[^>]*name=\"([^\"]+)\"[^>]*>(.*?)<\/textarea>/is", $html, $t)) {
        $fields[$t[1]] = html_entity_decode($t[2], ENT_QUOTES);
    }
    echo json_encode($fields);
' "$WORK/form.html" > "$PLAN"

# A tiny but valid JPEG, big enough to clear the 300x220 minimum.
php -r '$im = imagecreatetruecolor(400, 300);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 90, 40));
        imagejpeg($im, $argv[1], 85);' "$WORK/upload.jpg"

# Send the form back through a curl config file rather than a pile of -F
# flags. Quoting a value that came out of a shell expansion is a trap:
# escapeshellarg adds quotes, and quotes produced by *expansion* are not
# re-interpreted, so a value with a space silently becomes two arguments.
# A config file takes every value literally, spaces and all.
php -r '
    $fields = json_decode(file_get_contents($argv[1]), true) ?: [];
    $out = "";
    foreach ($fields as $k => $v) {
        // curl config values must stay on one line.
        $v = preg_replace("/\R/", " ", (string) $v);
        $out .= "form-string = \"" . $k . "="
              . str_replace(["\\", "\""], ["\\\\", "\\\""], $v) . "\"\n";
    }
    $out .= "form = \"photo=@" . $argv[2] . ";type=image/jpeg\"\n";
    echo $out;
' "$PLAN" "$WORK/upload.jpg" > "$WORK/post.conf"

# The token in the form was minted for this session; reuse it verbatim.
sed -i "s/^form-string = \"csrf=.*\$/form-string = \"csrf=$(csrf "$AJAR" "$EDIT_URL")\"/" "$WORK/post.conf"

photo_rel="$(db "SELECT image_path FROM menu_items WHERE id=$UP_ITEM")"
code="$(curl -s -b "$AJAR" -c "$AJAR" -o /dev/null -w '%{http_code}' \
        -K "$WORK/post.conf" "$EDIT_URL")"

photo_now="$(db "SELECT image_path FROM menu_items WHERE id=$UP_ITEM")"
if [ "$photo_now" != "$photo_rel" ] && [ -n "$photo_now" ]; then
    ok "upload replaced the photo ($photo_rel → $photo_now)"
else
    bad "upload did not change image_path (still '$photo_now', HTTP $code)"
    # The form keeps working and reports the reason as a flash message,
    # so show it — that is usually the whole diagnosis.
    curl -s -b "$AJAR" "$BASE/admin/items.php" \
        | grep -o '<div class="alert[^>]*>[^<]*' | head -2 \
        | sed 's/<[^>]*>//g; s/^/      the app said: /'
fi

case "$photo_now" in
    uploads/*)
        ok "photo stored under uploads/ (demo photographs untouched)"
        # image_path looks like uploads/menu/<file>.jpg; the thumbnails sit
        # beside it under thumbs/ and thumbs/tiny/.
        base="${photo_now##*/}"
        for rel in "uploads/menu/$base" "uploads/menu/thumbs/$base" "uploads/menu/thumbs/tiny/$base"; do
            if [ -f "$PUBLIC_DIR/$rel" ]; then ok "wrote $rel"; else bad "missing $rel"; fi
        done
        ;;
    *) bad "photo landed at '$photo_now' — expected it under uploads/" ;;
esac

# --------------------------------------------------------- 8. sign-in throttle
head_ "Sign-in throttle"

db "DELETE FROM login_attempts WHERE ip <> ''"

# Seven *different* addresses, so this exercises the per-IP rule... except
# the per-IP limit is 25, so it takes 26 tries before anything is refused.
blocked=""
for i in $(seq 1 26); do
    jar="$WORK/spray$i.txt"; rm -f "$jar"
    t="$(csrf "$jar" "$BASE/login.php")"
    out="$(curl -s -b "$jar" -c "$jar" -L \
        --data-urlencode "csrf=$t" --data-urlencode "email=spray$i@example.com" \
        --data-urlencode "password=wrong$i" "$BASE/login.php")"
    if printf '%s' "$out" | grep -qi "wait .* minute"; then blocked="$i"; break; fi
done
if [ "$blocked" = "26" ]; then
    ok "per-IP rule blocked the spray on attempt 26 (one address, many accounts)"
else
    bad "per-IP rule blocked at attempt '${blocked:-never}', expected 26"
fi

# Now the per-email rule, from a clean slate but the same address.
db "DELETE FROM login_attempts WHERE ip <> ''"
blocked=""
for i in $(seq 1 6); do
    jar="$WORK/one$i.txt"; rm -f "$jar"
    t="$(csrf "$jar" "$BASE/login.php")"
    out="$(curl -s -b "$jar" -c "$jar" -L \
        --data-urlencode "csrf=$t" --data-urlencode "email=$STUDENT_EMAIL" \
        --data-urlencode "password=wrong$i" "$BASE/login.php")"
    if printf '%s' "$out" | grep -qi "wait .* minute"; then blocked="$i"; break; fi
done
if [ "$blocked" = "6" ]; then
    ok "one account blocked on the 6th wrong password"
else
    bad "per-email rule blocked at attempt '${blocked:-never}', expected 6"
fi

# While blocked, the right password must not work either. A refusal is the
# login page coming back with a 200 and a "wait" notice, not a 302.
is "the right password is refused while blocked" \
   "$(login "$WORK/blocked.txt" "$STUDENT_EMAIL" "$STUDENT_PASS")" "200"
is "…and it created no session" \
   "$(curl -s -b "$WORK/blocked.txt" -o /dev/null -w '%{http_code}' "$BASE/orders.php")" "302"

# Let the window pass, then a good password should work and wipe the count.
db "DELETE FROM login_attempts WHERE ip <> ''"
is "a good password is accepted once the window has passed" \
   "$(login "$WORK/fresh.txt" "$STUDENT_EMAIL" "$STUDENT_PASS")" "302"

# Put three failures on the account, sign in properly, and the three must go.
for i in 1 2 3; do
    jar="$WORK/clear$i.txt"; rm -f "$jar"
    t="$(csrf "$jar" "$BASE/login.php")"
    curl -s -b "$jar" -c "$jar" -o /dev/null -L \
        --data-urlencode "csrf=$t" --data-urlencode "email=$STUDENT_EMAIL" \
        --data-urlencode "password=wrong$i" "$BASE/login.php"
done
before="$(db "SELECT COUNT(*) FROM login_attempts WHERE email='$STUDENT_EMAIL'")"
login "$WORK/fresh.txt" "$STUDENT_EMAIL" "$STUDENT_PASS" >/dev/null
after="$(db "SELECT COUNT(*) FROM login_attempts WHERE email='$STUDENT_EMAIL'")"
if [ "$before" -gt 0 ] && [ "$after" = "0" ]; then
    ok "signing in clears the account's failures ($before → 0)"
else
    bad "failures were not cleared on success ($before → $after)"
fi

# ------------------------------------------------------------ 9. put it back
head_ "Cleaning up after the test run"

# Delete the test order rather than leaving a cancelled one on the board.
db "DELETE FROM order_items WHERE order_id=$ORDER_ID"
db "DELETE FROM orders WHERE id=$ORDER_ID"
db "DELETE FROM login_attempts WHERE ip <> ''"
db "UPDATE users SET wallet = $WALLET_AT_START WHERE id=$UID_"
db "UPDATE menu_items SET stock = $STOCK_AT_START WHERE id=1"
ok "test order removed, wallet and stock restored to $WALLET_AT_START / $STOCK_AT_START"

case "$photo_now" in
    uploads/*)
        base="${photo_now##*/}"
        rm -f "$PUBLIC_DIR/uploads/menu/$base" \
              "$PUBLIC_DIR/uploads/menu/thumbs/$base" \
              "$PUBLIC_DIR/uploads/menu/thumbs/tiny/$base"
        db "UPDATE menu_items SET image_path='$photo_rel' WHERE id=$UP_ITEM"
        ok "uploaded test photo removed, item back on its demo photograph"
        # uploads/menu/thumbs may now be an empty tree; that is harmless and
        # the app recreates it on the next upload.
        ;;
esac

# The demo photographs themselves must be exactly as shipped. Anything
# missing here means an upload path deleted a file it did not own.
missing=0
for f in "$PUBLIC_DIR"/assets/menu/*.jpg; do [ -f "$f" ] || missing=$((missing + 1)); done
is "demo photographs still in place" "$missing" "0"

printf '\n  %d passed, %d failed\n' "$PASSED" "$FAILED"
if [ "$FAILED" -gt 0 ]; then
    printf '  \033[31mSomething regressed.\033[0m\n'
    exit 1
fi
printf '  \033[32mAll good.\033[0m\n'
