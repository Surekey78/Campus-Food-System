<?php
/**
 * includes/icons.php  —  the app's icon set
 *
 * Icons are inline SVG, defined once per page as a hidden <symbol> sprite and
 * referenced with <use>. That keeps the project's promises: no CDN, no icon
 * font, no build step, no extra request — and because the paths use
 * stroke="currentColor" they take the colour of whatever they sit in.
 *
 *   icon('cart')                        20px, inherits text colour
 *   icon('cart', 24, 'ico-lg')          sized and classed
 *   icon('receipt', 20, '', 'Orders')   labelled (for icons standing alone)
 *   item_icon($row['icon'])             a value stored in the database
 *
 * Database values
 * ---------------
 * The `icon` columns store a NAME from this file ('rice', 'noodles', …), not
 * an emoji. Names are kept to 8 characters so the existing VARCHAR(8) columns
 * and the order_items.item_icon snapshot need no migration. Rows written by
 * older versions hold an emoji instead; icon_from_emoji() maps those, so an
 * old database still renders correctly after upgrading.
 */

/** Every icon: name => human label (used by the admin pickers). */
function icon_names(): array
{
    return [
        // ---- interface
        'menu'        => 'Menu list',
        'cart'        => 'Shopping cart',
        'receipt'     => 'Order receipt',
        'kitchen'     => 'Cooking pot',
        'user'        => 'Person',
        'gauge'       => 'Dashboard gauge',
        'tag'         => 'Category tag',
        'users'       => 'Several people',
        'eye'         => 'Eye',
        'search'      => 'Magnifying glass',
        'alert'       => 'Warning',
        'clock'       => 'Clock',
        'bell'        => 'Bell',
        'check'       => 'Tick in a circle',
        'cross'       => 'X in a circle',
        'ban'         => 'Blocked',
        'sparkles'    => 'Sparkles',
        'note'        => 'Written note',
        'thumb'       => 'Thumbs up',
        'chevleft'    => 'Arrow left',
        'chevright'   => 'Arrow right',
        'plus'        => 'Plus',
        'minus'       => 'Minus',
        'x'           => 'Cross',
        'trash'       => 'Bin',
        'image'       => 'Picture',
        'pencil'      => 'Pencil',

        // ---- food (offered when a canteen picks an icon for a dish)
        'plate'       => 'Plate (default)',
        'rice'        => 'Rice bowl',
        'noodles'     => 'Noodles',
        'soup'        => 'Soup bowl',
        'snack'       => 'Snack box',
        'bread'       => 'Bread',
        'cake'        => 'Cake',
        'dessert'     => 'Cupcake',
        'drink'       => 'Drink cup',
        'coffee'      => 'Coffee',
        'water'       => 'Water',
        'can'         => 'Drink can',
        'fish'        => 'Fish',
        'meat'        => 'Meat on the bone',
        'cheese'      => 'Nacho',
        'leaf'        => 'Vegetables',
        'fruit'       => 'Fruit',
        'bowl'        => 'Filled bowl',
        'dumpling'    => 'Bun / dumpling',
        'roll'        => 'Roll',
    ];
}

/** Just the food icons — what a canteen may put on a dish or a category. */
function food_icon_names(): array
{
    static $food = [
        'plate', 'rice', 'noodles', 'soup', 'snack', 'bread', 'cake', 'dessert',
        'drink', 'coffee', 'water', 'can', 'fish', 'meat', 'cheese', 'leaf',
        'fruit', 'bowl', 'dumpling', 'roll',
    ];

    $all = icon_names();
    $out = [];
    foreach ($food as $name) {
        $out[$name] = $all[$name];
    }

    return $out;
}

function icon_exists(string $name): bool
{
    return array_key_exists($name, icon_names());
}

/**
 * The path data for every icon. Kept in one place so the sprite is generated
 * from a single source of truth.
 *
 * House rules for anything added here: 24x24 box, drawn with strokes (never
 * fills), no text, and simple enough to stay readable at 16px.
 */
function icon_paths(): array
{
    return [
        'menu'      => '<rect x="4" y="3.5" width="16" height="17" rx="2.5"/>'
                     . '<path d="M8.5 9h7M8.5 12.5h7M8.5 16h4"/>',

        'cart'      => '<path d="M3 4.5h2.2l2.3 10.2a1.6 1.6 0 0 0 1.6 1.3h7.4a1.6 1.6 0 0 0 1.6-1.2L20 8.5H6"/>'
                     . '<circle cx="9.5" cy="19.2" r="1.5"/><circle cx="17" cy="19.2" r="1.5"/>',

        'receipt'   => '<path d="M6 3.5h12v17l-2-1.4-2 1.4-2-1.4-2 1.4-2-1.4-2 1.4z"/>'
                     . '<path d="M9 8.5h6M9 12h6"/>',

        'kitchen'   => '<path d="M4 10h16v5.5a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z"/>'
                     . '<path d="M2.5 10h19M9 6.8c0-1.2 1-1.5 1-2.6M14 6.8c0-1.2 1-1.5 1-2.6"/>',

        'user'      => '<circle cx="12" cy="8.4" r="3.8"/>'
                     . '<path d="M4.6 20.5a7.4 7.4 0 0 1 14.8 0"/>',

        'gauge'     => '<path d="M3.8 18a8.2 8.2 0 0 1 16.4 0"/>'
                     . '<circle cx="12" cy="18" r="1.3"/>'
                     . '<path d="M12 18l3.6-4.8"/>',

        'tag'       => '<path d="M11.4 3.5H19a1.5 1.5 0 0 1 1.5 1.5v7.6a2 2 0 0 1-.6 1.4l-5.9 5.9a2 2 0 0 1-2.8 0l-6.1-6.1a2 2 0 0 1 0-2.8l5.9-5.9a2 2 0 0 1 1.4-.6z"/>'
                     . '<circle cx="16.2" cy="7.8" r="1.3"/>',

        'users'     => '<circle cx="9" cy="8" r="3.3"/>'
                     . '<path d="M3.2 19.6a5.8 5.8 0 0 1 11.6 0"/>'
                     . '<path d="M16.2 5.6a3.3 3.3 0 0 1 0 6.3"/>'
                     . '<path d="M17.6 13.4a5.8 5.8 0 0 1 3.2 5.2"/>',

        'eye'       => '<path d="M2.5 12S6.2 5.8 12 5.8 21.5 12 21.5 12 17.8 18.2 12 18.2 2.5 12 2.5 12z"/>'
                     . '<circle cx="12" cy="12" r="3"/>',

        'search'    => '<circle cx="11" cy="11" r="6"/>'
                     . '<path d="M15.4 15.4L21 21"/>',

        'alert'     => '<path d="M12 4.2 21 19.8H3z"/>'
                     . '<path d="M12 9.8v4"/>'
                     . '<circle cx="12" cy="16.8" r=".7" fill="currentColor" stroke="none"/>',

        'clock'     => '<circle cx="12" cy="12" r="8.4"/>'
                     . '<path d="M12 7v5.3l3.3 2"/>',

        'bell'      => '<path d="M6.6 10a5.4 5.4 0 0 1 10.8 0c0 3.9 1.6 5.4 1.6 5.4H5S6.6 13.9 6.6 10z"/>'
                     . '<path d="M10 18.4a2.1 2.1 0 0 0 4 0"/>',

        'check'     => '<circle cx="12" cy="12" r="8.4"/>'
                     . '<path d="M8.3 12.3l2.6 2.6 4.9-5.2"/>',

        'cross'     => '<circle cx="12" cy="12" r="8.4"/>'
                     . '<path d="M9.2 9.2l5.6 5.6M14.8 9.2l-5.6 5.6"/>',

        'ban'       => '<circle cx="12" cy="12" r="8.4"/>'
                     . '<path d="M6.1 6.1l11.8 11.8"/>',

        'sparkles'  => '<path d="M9.2 3.6l1.4 3.6 3.6 1.4-3.6 1.4-1.4 3.6-1.4-3.6L4.2 8.6l3.6-1.4z"/>'
                     . '<path d="M17 13.2l1 2.4 2.4 1-2.4 1-1 2.4-1-2.4-2.4-1 2.4-1z"/>',

        'note'      => '<path d="M6.5 3.5h7L18 7.8v12.7a1 1 0 0 1-1 1H6.5a1 1 0 0 1-1-1v-16a1 1 0 0 1 1-1z"/>'
                     . '<path d="M13.4 3.6v4.3H18"/>'
                     . '<path d="M9 12.6h6M9 16h4"/>',

        'thumb'     => '<path d="M7.2 10.6v9.2H4.8a1.2 1.2 0 0 1-1.2-1.2v-6.8a1.2 1.2 0 0 1 1.2-1.2z"/>'
                     . '<path d="M7.2 10.6l3.9-6.8a2 2 0 0 1 2 2v3.4h4.3a2 2 0 0 1 2 2.4l-1.1 5.9a2 2 0 0 1-2 1.6H7.2"/>',

        'chevleft'  => '<path d="M14.5 5.5 8 12l6.5 6.5"/>',
        'chevright' => '<path d="M9.5 5.5 16 12l-6.5 6.5"/>',

        'plus'      => '<path d="M12 5.5v13M5.5 12h13"/>',
        'minus'     => '<path d="M5.5 12h13"/>',
        'x'         => '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>',

        'trash'     => '<path d="M4.5 7h15"/>'
                     . '<path d="M9.5 7V5.3a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1V7"/>'
                     . '<path d="M6.6 7l.8 12.2a1.5 1.5 0 0 0 1.5 1.4h6.2a1.5 1.5 0 0 0 1.5-1.4L17.4 7"/>',

        'image'     => '<rect x="3.5" y="5" width="17" height="14" rx="2"/>'
                     . '<circle cx="9" cy="10" r="1.4"/>'
                     . '<path d="M4 17.2l4.4-3.9 3.4 2.9 3.1-2.6 4.6 4.1"/>',

        'pencil'    => '<path d="M16.7 4.4l2.9 2.9L8.4 18.5l-3.7.8.8-3.7z"/>'
                     . '<path d="M4.5 20.5h15"/>',

        // ------------------------------------------------------------- food
        'plate'     => '<circle cx="12" cy="12" r="8.4"/>'
                     . '<circle cx="12" cy="12" r="4.4"/>',

        'rice'      => '<path d="M4 12.6h16a8 8 0 0 1-8 8 8 8 0 0 1-8-8z"/>'
                     . '<path d="M7.6 12.6a4.4 4.4 0 0 1 8.8 0"/>'
                     . '<path d="M7 20.6h10"/>',

        'noodles'   => '<path d="M4 13.2h16a8 8 0 0 1-8 8 8 8 0 0 1-8-8z"/>'
                     . '<path d="M9 6c0 2-1 2.6-1 4.2M12.5 4.8c0 2.3-1 2.9-1 5.4M16 6c0 2-1 2.6-1 4.2"/>',

        'soup'      => '<path d="M4 13.2h16a8 8 0 0 1-8 8 8 8 0 0 1-8-8z"/>'
                     . '<path d="M9 7.4c0-1.1.9-1.3.9-2.4M13.4 7.4c0-1.1.9-1.3.9-2.4"/>',

        'snack'     => '<path d="M6.2 10.4h11.6L16.6 20.5H7.4z"/>'
                     . '<path d="M9 10.4V5.6M12 10.4V4.6M15 10.4V5.6"/>'
                     . '<path d="M7.4 13.6h9.2"/>',

        'bread'     => '<path d="M7.2 17.8c-2.6-2.6-2.1-7.2 1.5-10.8s8.2-4.1 10.8-1.5 2.1 7.2-1.5 10.8-8.2 4.1-10.8 1.5z"/>'
                     . '<path d="M10 12.4l1.9 1.9M13.1 9.3l1.9 1.9"/>',

        'cake'      => '<path d="M4.5 20.5v-5.8a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v5.8z"/>'
                     . '<path d="M4.5 16.4h15"/>'
                     . '<path d="M8.4 12.7V9.4M12 12.7V8.6M15.6 12.7V9.4"/>',

        'dessert'   => '<path d="M5.6 12.2h12.8l-1.3 8.3H6.9z"/>'
                     . '<path d="M6.4 12.2a5.6 5.6 0 0 1 11.2 0"/>'
                     . '<circle cx="12" cy="4.4" r="1.4"/>',

        'drink'     => '<path d="M6.6 8h10.8l-1.2 12.5H7.8z"/>'
                     . '<path d="M13.2 8l2.6-4.5"/>'
                     . '<path d="M6.9 11.6h10.2"/>',

        'coffee'    => '<path d="M4.6 8.4h11.6v6.2a4 4 0 0 1-4 4H8.6a4 4 0 0 1-4-4z"/>'
                     . '<path d="M16.2 9.8h1.6a2.5 2.5 0 0 1 0 5h-1.6"/>'
                     . '<path d="M8.4 5.6V4M12 5.6V4"/>',

        'water'     => '<path d="M12 3.6s5.4 6.3 5.4 10.1a5.4 5.4 0 0 1-10.8 0C6.6 9.9 12 3.6 12 3.6z"/>',

        'can'       => '<path d="M8 4.6h8v15a1.5 1.5 0 0 1-1.5 1.5h-5A1.5 1.5 0 0 1 8 19.6z"/>'
                     . '<path d="M8 8.2h8M8 16.2h8"/>',

        'fish'      => '<path d="M20.4 12c-2.6 3.1-5.2 4.6-8.4 4.6S6.4 15.1 4.2 12c2.2-3.1 4.6-4.6 7.8-4.6s5.8 1.5 8.4 4.6z"/>'
                     . '<path d="M4.2 12 2.4 8.6v6.8z"/>'
                     . '<circle cx="16" cy="11.4" r=".8" fill="currentColor" stroke="none"/>',

        'meat'      => '<circle cx="14.6" cy="9.4" r="5.2"/>'
                     . '<path d="M11.2 12.8 5.6 18.4"/>'
                     . '<circle cx="4.8" cy="19.2" r="1.9"/>',

        'cheese'    => '<path d="M4.4 16.8 10 7.6h7.6a1.7 1.7 0 0 1 1.5 2.5l-3.5 6.7z"/>'
                     . '<circle cx="12.2" cy="12.6" r="1.1" fill="currentColor" stroke="none"/>'
                     . '<circle cx="15.8" cy="14" r="1.1" fill="currentColor" stroke="none"/>',

        'leaf'      => '<path d="M20.4 4.2C11.2 4.8 5.4 9.4 5.4 16.6c0 1.5.4 2.8.4 2.8s1.2.4 2.8.4c7.2 0 11.8-5.8 11.8-15.6z"/>'
                     . '<path d="M15.6 8.6 5.6 19"/>',

        'fruit'     => '<path d="M12 6.6c-3.6 0-6.4 2.6-6.4 6.2S8.4 20 12 20s6.4-3.6 6.4-7.2S15.6 6.6 12 6.6z"/>'
                     . '<path d="M12 6.6V3.8"/>'
                     . '<path d="M12.4 5.8c1.4-1.4 3.2-1.8 4.6-1.2-.4 1.4-1.8 2.4-3.2 2.4"/>',

        'bowl'      => '<path d="M4 12.4h16a8 8 0 0 1-16 0z"/>'
                     . '<path d="M12 12.4V7.4"/>'
                     . '<path d="M9.6 5.4h4.8"/>',

        'dumpling'  => '<path d="M12 5.4c3.6 0 6.6 3 6.6 6.6H5.4c0-3.6 3-6.6 6.6-6.6z"/>'
                     . '<path d="M5.4 12h13.2v2.6a3 3 0 0 1-3 3H8.4a3 3 0 0 1-3-3z"/>'
                     . '<path d="M12 5.4V12"/>',

        'roll'      => '<path d="M4.8 16.4 15.6 5.6a3.1 3.1 0 0 1 4.4 4.4L9.2 20.8a3.1 3.1 0 0 1-4.4-4.4z"/>'
                     . '<path d="M7.4 18 18.2 7.2"/>',
    ];
}

/**
 * The hidden sprite, printed once per page (see includes/footer.php).
 */
function icon_sprite(): string
{
    $out = '<svg class="icon-sprite" aria-hidden="true" focusable="false" width="0" height="0"'
         . ' style="position:absolute;overflow:hidden">';

    foreach (icon_paths() as $name => $paths) {
        $out .= '<symbol id="i-' . e($name) . '" viewBox="0 0 24 24">' . $paths . '</symbol>';
    }

    return $out . '</svg>';
}

/**
 * One icon as inline SVG.
 *
 * @param string $name   a name from icon_paths()
 * @param int    $size   pixel size (the sprite is a square 24x24 box)
 * @param string $class  extra CSS classes
 * @param string $label  set this when the icon is the only thing telling the
 *                       user something (no visible text): it becomes the
 *                       accessible name instead of being hidden from
 *                       screen readers.
 */
function icon(string $name, int $size = 20, string $class = '', string $label = ''): string
{
    if (!icon_exists($name)) {
        $name = 'plate';
    }

    $cls  = trim('ico ico-' . $name . ($class !== '' ? ' ' . $class : ''));
    $size = max(12, min(96, $size));

    $a11y = $label !== ''
        ? ' role="img" aria-label="' . e($label) . '"'
        : ' aria-hidden="true"';

    return '<svg class="' . e($cls) . '" width="' . $size . '" height="' . $size . '"'
         . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
         . ' stroke-linecap="round" stroke-linejoin="round" focusable="false"' . $a11y . '>'
         . '<use href="#i-' . e($name) . '"></use></svg>';
}

/**
 * A radio grid of food icons, for the admin forms.
 *
 * A grid of radios rather than a <select>: every option is visible, each one
 * is a 44px tap target on a phone, and it needs no JavaScript. The current
 * value is preselected, and anything already stored is mapped to its closest
 * icon first (see icon_name_of()).
 *
 * @param string $field     the input name to post back
 * @param string $selected  the stored value (a name, or an older emoji)
 * @param string $label     heading text
 */
function icon_picker(string $name, ?string $selected, string $label = 'Icon'): string
{
    $current = icon_name_of($selected);
    $food    = food_icon_names();

    // An icon outside the food set (say 'sparkles') is still honoured, and
    // offered back, rather than silently switching the dish to a plate.
    if (!isset($food[$current]) && icon_exists($current)) {
        $food = [$current => icon_names()[$current]] + $food;
    }

    $out = '<div class="field">'
         . '<span class="field-label">' . e($label) . '</span>'
         . '<div class="icon-picker" role="radiogroup" aria-label="' . e($label) . '">';

    foreach ($food as $iconName => $text) {
        $id = 'icon-' . $iconName;

        $out .= '<label class="icon-option" for="' . e($id) . '" title="' . e($text) . '">'
              . '<input type="radio" id="' . e($id) . '" name="' . e($name) . '"'
              . ' value="' . e($iconName) . '"' . ($iconName === $current ? ' checked' : '') . '>'
              . icon($iconName, 22)
              . '<span class="icon-option-name">' . e($iconName) . '</span>'
              . '</label>';
    }

    return $out . '</div>'
         . '<div class="hint">A simple drawing of the dish, so the menu still reads '
         . 'well when a photo is missing. Stored as a name, not an emoji.</div>'
         . '</div>';
}

/**
 * Translate an emoji stored by an older version into an icon name, so a
 * database written before this change still renders properly.
 */
function icon_from_emoji(string $value): ?string
{
    static $map = [
        // mains
        '🍛' => 'rice',  '🍚' => 'rice',  '🍱' => 'rice',  '🍙' => 'rice',
        '🍜' => 'noodles', '🍝' => 'noodles',
        '🍲' => 'soup',  '🥣' => 'soup',
        '🥘' => 'bowl',  '🥟' => 'dumpling', '🍥' => 'dumpling',
        '🍢' => 'snack', '🍡' => 'snack', '🍟' => 'snack', '🍿' => 'snack',
        '🌯' => 'roll',  '🥙' => 'roll',

        // protein & produce
        '🍗' => 'meat',  '🍖' => 'meat',  '🥓' => 'meat',  '🥩' => 'meat',
        '🐟' => 'fish',  '🍤' => 'fish',  '🦐' => 'fish',
        '🥗' => 'leaf',  '🥬' => 'leaf',  '🥦' => 'leaf',
        '🍌' => 'fruit', '🥭' => 'fruit', '🍎' => 'fruit', '🍊' => 'fruit',
        '🍉' => 'fruit', '🍇' => 'fruit', '🥥' => 'fruit', '🍍' => 'fruit',
        '🧀' => 'cheese', '🌮' => 'cheese', '🍕' => 'cheese',

        // bakery & sweets
        '🥖' => 'bread', '🍞' => 'bread', '🥐' => 'bread', '🥯' => 'bread',
        '🧁' => 'dessert', '🍰' => 'cake', '🍩' => 'cake', '🍪' => 'cake',
        '🍮' => 'dessert', '🍧' => 'dessert', '🍨' => 'dessert', '🍦' => 'dessert',
        '🍫' => 'cake',  '🍬' => 'cake',

        // drinks
        '☕' => 'coffee', '🍵' => 'coffee',
        '🧋' => 'drink', '🥤' => 'drink', '🧃' => 'drink', '🍹' => 'drink',
        '🥛' => 'water', '💧' => 'water', '🍶' => 'water',
        '🥫' => 'can',   '🍺' => 'can',   '🥂' => 'can',

        // handful of generic plates
        '🍽' => 'plate', '🍴' => 'plate', '🥄' => 'plate', '🛒' => 'cart',
        '🔍' => 'search', '🧾' => 'receipt',
    ];

    // Strip any variation selector / zero-width joiner before looking up.
    $key = str_replace(["\u{FE0F}", "\u{FE0E}", "\u{200D}"], '', $value);

    if (isset($map[$key])) {
        return $map[$key];
    }

    // Multi-part emoji (👨‍🍳): try the first character on its own.
    $first = mb_substr($key, 0, 1, 'UTF-8');

    return $map[$first] ?? null;
}

/**
 * Resolve a value from the database — an icon name, or an emoji written by an
 * older version — into inline SVG. Anything unrecognised becomes a plate.
 */
function item_icon(?string $value, int $size = 20, string $class = '', string $label = ''): string
{
    $name = icon_name_of($value);

    return icon($name, $size, $class, $label);
}

/** Just the name, for when a caller needs it rather than the markup. */
function icon_name_of(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return 'plate';
    }

    // A name from our own list?
    if (preg_match('/^[a-z][a-z0-9-]{0,7}$/', $value) && icon_exists($value)) {
        return $value;
    }

    // Older rows hold an emoji.
    $mapped = icon_from_emoji($value);
    if ($mapped !== null) {
        return $mapped;
    }

    return 'plate';
}

/**
 * Validate a posted icon, falling back to a safe default.
 *
 * Names are also checked against the database column: `icon` is VARCHAR(8)
 * from the original schema, and a longer name would be silently truncated on
 * save — so anything that doesn't fit is refused here instead.
 */
function icon_clean(?string $posted, string $fallback = 'plate'): string
{
    $posted = trim((string) $posted);

    if ($posted !== ''
        && preg_match('/^[a-z][a-z0-9-]{0,7}$/', $posted)
        && icon_exists($posted)
        && strlen($posted) <= 8) {
        return $posted;
    }

    // Tolerate an emoji if one arrives from an older form or a bookmarklet.
    $mapped = icon_from_emoji($posted);

    return ($mapped !== null && strlen($mapped) <= 8) ? $mapped : $fallback;
}

/** The longest icon name that fits the VARCHAR(8) columns. */
function icon_names_that_fit(): array
{
    return array_filter(icon_names(), static fn ($_, $name) => strlen($name) <= 8, ARRAY_FILTER_USE_BOTH);
}
