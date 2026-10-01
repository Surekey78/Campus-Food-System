<?php
/**
 * tools/make-icon-sheet.php  —  regenerate docs/icons.png
 *
 * The icon set lives in includes/icons.php as SVG path data. A picture of it
 * in the README is only useful if it cannot quietly go stale, so this script
 * builds that picture *from the same array the app draws from*: add an icon,
 * run this, and the documentation updates itself.
 *
 *   php tools/make-icon-sheet.php            write docs/icons.png
 *   php tools/make-icon-sheet.php --html     leave the HTML behind too
 *
 * Needs a headless Chromium (or Chrome) on PATH. If none is found the script
 * says so and writes nothing — a stale screenshot is worse than none.
 *
 * CLI only, like every other file in tools/.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script only runs from the command line.\n");
}

require_once __DIR__ . '/../includes/functions.php';

$root    = dirname(__DIR__);
$outPng  = $root . '/docs/icons.png';
$keepHtml = in_array('--html', $argv, true);
$tmpHtml = $root . '/public/_icon-sheet.html';

// --------------------------------------------------------------- find a browser
$browser = null;
foreach (['chromium', 'chromium-browser', 'google-chrome', 'chrome'] as $name) {
    $path = trim((string) @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
    if ($path !== '') {
        $browser = $path;
        break;
    }
}

if ($browser === null) {
    fwrite(STDERR, "✗ No headless browser found (tried chromium, chromium-browser, google-chrome).\n");
    fwrite(STDERR, "  Install one, or leave docs/icons.png as it is.\n");
    exit(1);
}

// ------------------------------------------------------------ build the page
$all    = icon_names();
$food   = food_icon_names();
$legacy = ['🍛', '🥘', '🍜', '🍲', '🐟', '🍗', '🥗', '🥖', '🧁', '🍮',
           '🥤', '💧', '🍢', '🍽️', '🍧', '🥥'];

$iface = array_diff_key($all, $food);

$html  = '<!doctype html><meta charset="utf-8">';
$html .= '<style>
 body { font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
        margin:0; padding:30px; background:#f6f4f1; color:#1f2430; width:1000px; }
 h2 { font-size:13px; text-transform:uppercase; letter-spacing:.09em; color:#8a93a3;
      margin:26px 0 12px; font-weight:700; }
 h2:first-child { margin-top:0; }
 .grid { display:grid; grid-template-columns:repeat(10,1fr); gap:8px; }
 .cell { background:#fff; border:1px solid #e6e8ee; border-radius:10px;
         padding:11px 4px 8px; text-align:center; }
 .cell svg { color:#e8590c; }
 .cell b { display:block; margin-top:7px; font-size:10px; color:#5b6472;
           font-family:ui-monospace,monospace; font-weight:600; }
 .note { color:#8a93a3; font-size:12px; margin:8px 0 0; }
</style>';

$html .= icon_sprite();

$html .= '<h2>Interface icons — ' . count($iface) . '</h2><div class="grid">';
foreach ($iface as $name => $text) {
    $html .= '<div class="cell">' . icon($name, 26) . '<b>' . e($name) . '</b></div>';
}
$html .= '</div>';

$html .= '<h2>Food icons — ' . count($food)
       . ' — chosen per dish in the admin, stored in the database by name</h2><div class="grid">';
foreach ($food as $name => $text) {
    $html .= '<div class="cell">' . icon($name, 26) . '<b>' . e($name) . '</b></div>';
}
$html .= '</div>';

$html .= '<h2>Emoji written by an earlier version still resolve</h2><div class="grid">';
foreach ($legacy as $emoji) {
    $html .= '<div class="cell">' . item_icon($emoji, 26)
           . '<b>' . e(icon_name_of($emoji)) . '</b></div>';
}
$html .= '</div><p class="note">icon_from_emoji() maps these on the way in, so an upgraded '
       . 'database renders correctly and the next save stores a name. Anything unrecognised '
       . 'falls back to the plate.</p>';

if (!is_dir(dirname($tmpHtml))) {
    fwrite(STDERR, "✗ public/ is missing — is this the project root?\n");
    exit(1);
}

file_put_contents($tmpHtml, $html);

// --------------------------------------------------------------- screenshot
$port = 8123;
// 'exec' matters: without it proc_open starts a shell, proc_terminate kills
// the shell, and the php -S child is orphaned — one leaked server per run.
$server = proc_open(
    'exec php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root . '/public'),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);

if (!is_resource($server)) {
    @unlink($tmpHtml);
    fwrite(STDERR, "✗ Could not start a temporary PHP server.\n");
    exit(1);
}

usleep(600000);   // let it bind

$url = 'http://127.0.0.1:' . $port . '/_icon-sheet.html';

exec(sprintf(
    '%s --headless=new --no-sandbox --disable-gpu --hide-scrollbars '
    . '--force-device-scale-factor=1.6 --window-size=1060,1400 '
    . '--virtual-time-budget=5000 --screenshot=%s %s 2>/dev/null',
    escapeshellarg($browser),
    escapeshellarg($outPng),
    escapeshellarg($url)
));

proc_terminate($server);
proc_close($server);

if (!$keepHtml) {
    @unlink($tmpHtml);
}

if (!is_file($outPng) || filesize($outPng) === 0) {
    fwrite(STDERR, "✗ The browser did not produce a screenshot.\n");
    exit(1);
}

printf(
    "✓ Wrote %s (%d icons, %.0f KB)%s\n",
    str_replace($root . '/', '', $outPng),
    count($all),
    filesize($outPng) / 1024,
    $keepHtml ? ' — HTML left at ' . str_replace($root . '/', '', $tmpHtml) : ''
);
