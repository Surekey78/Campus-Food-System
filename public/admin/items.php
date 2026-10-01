<?php
/**
 * public/admin/items.php  —  manage the menu (add / edit / delete / restock)
 * One page does everything: the form doubles as "add new" and "edit".
 */
require_once __DIR__ . '/../../includes/functions.php';

require_admin();

$categories = db_all('SELECT id, name, icon FROM categories ORDER BY sort_order, name');
$categoryIds = array_map(static fn ($c) => (int) $c['id'], $categories);

$editing = null;
$errors  = [];

/**
 * Validate and store an uploaded photo for a menu item.
 *
 * Security notes:
 *  - the real type is decided by getimagesize(), never by the browser's
 *    Content-Type or the file extension;
 *  - the file is re-encoded through the image library, which strips EXIF
 *    and any embedded payload;
 *  - it is saved under a name we generate ourselves, so a crafted
 *    filename can never escape the upload directory;
 *  - the uploads folder is given an .htaccess that stops PHP inside it
 *    from ever executing, in case someone manages to plant a script.
 *
 * @return array{0:?string,1:?string} [relative path, error message]
 */
function store_menu_photo(array $file, int $itemId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];                       // nothing uploaded
    }

    // Re-encoding needs GD. Without it, fail with something readable
    // instead of a fatal error halfway through the request.
    if (!function_exists('imagecreatetruecolor')) {
        return [null, 'Photo uploads need the PHP GD extension. '
                    . 'Install it (Debian/Ubuntu: sudo apt install php-gd, '
                    . 'Termux: already included with php) and reload the page.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That image is too large for the server limit.',
            UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted. Please try again.',
            default                                   => 'The image could not be uploaded (code ' . $file['error'] . ').',
        }];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'That upload did not come from a form.'];
    }

    if ($file['size'] > 6 * 1024 * 1024) {
        return [null, 'Please use an image under 6 MB.'];
    }

    // Trust the bytes, not the filename.
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return [null, 'That file is not a readable image.'];
    }

    [$width, $height] = $info;
    $mime = $info['mime'] ?? '';

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return [null, 'Photos must be JPEG, PNG or WebP.'];
    }

    if ($width < 300 || $height < 220) {
        return [null, "That image is only {$width}×{$height}. Use at least 300×220 so it looks sharp."];
    }

    // Re-encode through GD: normalises the format and drops metadata.
    $source = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => @imagecreatefrompng($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
        default      => false,
    };
    if ($source === false) {
        return [null, 'That image could not be processed.'];
    }

    // Scale down so the menu stays quick on a phone data plan.
    $maxW = 1000;
    if ($width > $maxW) {
        $newW = $maxW;
        $newH = (int) round($height * ($maxW / $width));
    } else {
        $newW = $width;
        $newH = $height;
    }

    $canvas = imagecreatetruecolor($newW, $newH);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);

    $uploadDir = PUBLIC_ROOT . '/uploads/menu';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
        return [null, 'Could not create the uploads folder.'];
    }

    // A name we choose: no user input in the path at all.
    $filename = 'item-' . bin2hex(random_bytes(8)) . '.jpg';
    $absolute = $uploadDir . '/' . $filename;

    if (!imagejpeg($canvas, $absolute, 82)) {
        return [null, 'Could not save the image. Check folder permissions.'];
    }

    imagedestroy($canvas);
    imagedestroy($source);

    // Belt and braces: no script execution inside the uploads folder.
    $guard = PUBLIC_ROOT . '/uploads/.htaccess';
    if (!file_exists($guard)) {
        @file_put_contents($guard, <<<'HT'
# Uploaded images live here, so nothing in this folder may ever run.
php_flag engine off
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .cgi .pl
<FilesMatch "\.(php|phtml|php[0-9]|phps|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
HT);
    }

    // Write the display sizes straight away so a fresh upload is fast on
    // a phone from the first page load, not just after a maintenance run.
    menu_photo_make_thumbs('uploads/menu/' . $filename);

    return ['uploads/menu/' . $filename, null];
}

// ------------------------------------------------------------- save (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? 'save';
    $id     = (int) ($_POST['id'] ?? 0);

    // ---------------------------------------------------------- delete
    if ($action === 'delete') {
        // Take the uploaded photo with it, or the file lingers forever with
        // nothing pointing at it. Shipped demo photos are left alone for the
        // same reason as photo replacement below.
        $row = db_one('SELECT name, image_path FROM menu_items WHERE id = ?', [$id]);

        if ($row && $row['image_path'] && str_starts_with((string) $row['image_path'], 'uploads/')) {
            @unlink(PUBLIC_ROOT . '/' . $row['image_path']);
        }

        db_exec('DELETE FROM menu_items WHERE id = ?', [$id]);
        flash(($row ? '“' . $row['name'] . '”' : 'Menu item') . ' deleted.', 'warn');
        redirect('/admin/items.php');
    }

    // ------------------------------------------------- remove demo data
    // The one-click way to get from "sample canteen" to "real menu".
    // Deletes only rows flagged is_demo = 1, plus the orders the sample
    // students placed, and the downloaded stock photos themselves.
    if ($action === 'purge_demo') {
        $conn = db();
        $conn->begin_transaction();

        try {
            // Collect the photos first so the files can be unlinked too.
            $photos = db_all(
                "SELECT image_path FROM menu_items
                  WHERE is_demo = 1 AND image_path LIKE 'assets/menu/%'"
            );

            // Sample orders belong to the sample students; orders cascade
            // to order_items via the foreign key.
            $demoUsers = db_all('SELECT id FROM users WHERE is_demo = 1');
            foreach ($demoUsers as $demoUser) {
                db_exec('DELETE FROM orders WHERE user_id = ?', [(int) $demoUser['id']]);
            }

            db_exec('DELETE FROM menu_items WHERE is_demo = 1');

            // Categories are only removed once nothing points at them.
            db_exec(
                'DELETE FROM categories
                  WHERE id NOT IN (SELECT DISTINCT category_id FROM menu_items)
                    AND name IN ("Rice Meals","Noodles & Soup","Snacks",
                                 "Bread & Pastries","Drinks","Desserts")'
            );

            $conn->commit();

            $removed = 0;
            foreach ($photos as $photo) {
                $file = PUBLIC_ROOT . '/' . $photo['image_path'];
                if (str_starts_with($photo['image_path'], 'assets/menu/') && is_file($file)) {
                    @unlink($file);
                    $removed++;
                }
            }

            // Sweep the demo photo folder for images no row points at any
            // more — for example one whose item had its photo removed
            // manually before the purge, which would otherwise linger.
            $inUse = [];
            foreach (db_all('SELECT image_path FROM menu_items WHERE image_path IS NOT NULL') as $row) {
                $inUse[$row['image_path']] = true;
            }
            $demoDir = PUBLIC_ROOT . '/assets/menu';
            foreach ((array) glob($demoDir . '/*.jpg') as $file) {
                if (!isset($inUse['assets/menu/' . basename($file)])) {
                    @unlink($file);
                    $removed++;
                }
            }

            flash(
                'Demo data removed — menu and sample orders cleared'
                . ($removed > 0 ? ", $removed stock photo(s) deleted" : '')
                . '. The demo accounts are still here — create your own administrator on '
                . 'the Staff accounts page, sign in as it, then delete the demo ones there.',
                'warn'
            );
        } catch (Throwable $ex) {
            $conn->rollback();
            flash('Could not remove the demo data: ' . $ex->getMessage(), 'error');
        }

        redirect('/admin/items.php');
    }

    // ------------------------------------------------- quick stock/flag
    if ($action === 'quick') {
        $stock = max(0, (int) ($_POST['stock'] ?? 0));
        $on    = (int) ($_POST['is_available'] ?? 0) === 1 ? 1 : 0;

        db_exec(
            'UPDATE menu_items SET stock = ?, is_available = ? WHERE id = ?',
            [$stock, $on, $id]
        );
        flash('Stock updated.');
        redirect('/admin/items.php');
    }

    // ------------------------------------------------------------ save
    $name        = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $price       = (string) ($_POST['price'] ?? '0');
    $icon        = icon_clean($_POST['icon'] ?? null, 'plate');
    $categoryId  = (int) ($_POST['category_id'] ?? 0);
    $stock       = max(0, (int) ($_POST['stock'] ?? 0));
    $prep        = max(1, (int) ($_POST['prep_minutes'] ?? 10));
    $available   = (int) ($_POST['is_available'] ?? 0) === 1 ? 1 : 0;

    if ($name === '') {
        $errors[] = 'The item name is required.';
    }
    if (!is_numeric($price) || (float) $price < 0) {
        $errors[] = 'Price must be a number of 0 or more.';
    }
    if (!in_array($categoryId, $categoryIds, true)) {
        $errors[] = 'Please choose a valid category.';
    }

    if ($errors === []) {
        /* ---- work out what happens to the photo -------------------

           The upload is processed BEFORE the row is written. Earlier
           versions wrote the row first, which meant a failed upload left
           the item with its credit cleared but its photo unchanged — a
           half-applied edit. Doing the file first keeps the two in step. */

        $removePhoto = (int) ($_POST['remove_photo'] ?? 0) === 1;

        $existing = $id > 0
            ? db_one('SELECT image_path, image_credit, image_source FROM menu_items WHERE id = ?', [$id])
            : null;

        $newPath   = $existing['image_path']   ?? null;
        $newCredit = $existing['image_credit'] ?? null;
        $newSource = $existing['image_source'] ?? null;

        if ($removePhoto) {                       // "show the icon only"
            $newPath = $newCredit = $newSource = null;
        }

        // The filename is random rather than id-based, so the photo can be
        // stored before an INSERT has produced a row id.
        [$uploadedPath, $uploadError] = store_menu_photo($_FILES['photo'] ?? [], $id);

        $photoWarning = null;

        if ($uploadError !== null) {
            // Keep the canteen's other edits and tell them what failed,
            // rather than throwing the whole form away.
            $photoWarning = $uploadError;
        } elseif ($uploadedPath !== null) {
            // A canteen's own photo carries no third-party attribution:
            // the old credit described the old image, so it goes with it.
            $newPath   = $uploadedPath;
            $newCredit = null;
            $newSource = null;
        }

        if ($id > 0) {
            db_exec(
                'UPDATE menu_items
                    SET category_id = ?, name = ?, description = ?, price = ?,
                        icon = ?, stock = ?, prep_minutes = ?, is_available = ?,
                        image_path = ?, image_credit = ?, image_source = ?
                  WHERE id = ?',
                [$categoryId, $name, $description ?: null, (float) $price, $icon,
                 $stock, $prep, $available, $newPath, $newCredit, $newSource, $id]
            );

            /* ---- clean up the file the old photo lived in ---------
               Two cases, and earlier versions missed the second one:
                 replaced -> a new file was just written, remove the old;
                 removed  -> no new file, but the old one is now orphaned. */
            if (!empty($existing['image_path'])) {
                $oldPath  = (string) $existing['image_path'];
                $replaced = $uploadedPath !== null;

                /* Only files this app wrote at runtime are ever deleted.
                   assets/menu/ photos ship with the project, so replacing one
                   leaves the file on disk: reinstalling the demo data (or a
                   second install sharing this folder) must still find a
                   working image. The explicit "Remove all demo data" sweep is
                   what clears stray demo photos, and it knows about this. */
                if (str_starts_with($oldPath, 'uploads/') && ($replaced || $removePhoto)) {
                    @unlink(PUBLIC_ROOT . '/' . $oldPath);
                }
            }

            if ($photoWarning !== null) {
                flash('“' . $name . '” saved, but the photo was not: ' . $photoWarning, 'warn');
            } else {
                flash($removePhoto
                    ? '“' . $name . '” updated — photo removed.'
                    : '“' . $name . '” updated.');
            }
        } else {
            try {
                db_exec(
                    'INSERT INTO menu_items
                        (category_id, name, description, price, icon, stock,
                         prep_minutes, is_available, image_path)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$categoryId, $name, $description ?: null, (float) $price, $icon,
                     $stock, $prep, $available, $newPath]
                );
            } catch (Throwable $ex) {
                // Don't leave an orphaned file behind if the insert failed.
                if ($uploadedPath !== null) {
                    @unlink(PUBLIC_ROOT . '/' . $uploadedPath);
                }
                throw $ex;
            }

            if ($photoWarning !== null) {
                flash('“' . $name . '” added, but the photo was not: ' . $photoWarning, 'warn');
            } elseif ($uploadedPath !== null) {
                flash('“' . $name . '” added to the menu with its photo.');
            } else {
                flash('“' . $name . '” added to the menu.');
            }
        }

        redirect('/admin/items.php');
    }

    // Validation failed — re-render the form with what was typed.
    $editing = [
        'id' => $id, 'name' => $name, 'description' => $description,
        'price' => $price, 'icon' => $icon, 'category_id' => $categoryId,
        'stock' => $stock, 'prep_minutes' => $prep, 'is_available' => $available,
    ];
}

// ------------------------------------------------------------- edit mode
if ($editing === null && isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    $editing = db_one('SELECT * FROM menu_items WHERE id = ?', [(int) $_GET['edit']]);

    if (!$editing) {
        flash('That item no longer exists.', 'error');
        redirect('/admin/items.php');
    }
}

// ------------------------------------------------------------------- list
$filterCat = isset($_GET['cat']) && ctype_digit((string) $_GET['cat']) ? (int) $_GET['cat'] : 0;
$term      = trim((string) ($_GET['q'] ?? ''));

$where  = ['1'];
$params = [];
if ($filterCat > 0) {
    $where[]  = 'm.category_id = ?';
    $params[] = $filterCat;
}
if ($term !== '') {
    $where[]  = 'm.name LIKE ?';
    $params[] = '%' . $term . '%';
}

$items = db_all(
    'SELECT m.*, c.name AS category_name, c.icon AS category_icon
       FROM menu_items m
       JOIN categories c ON c.id = m.category_id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY c.sort_order, m.name',
    $params
);

// How much of this menu is still sample content? Drives the warning banner.
$demoCount = (int) db_val('SELECT COUNT(*) FROM menu_items WHERE is_demo = 1', [], 0);
$photoCount = (int) db_val(
    'SELECT COUNT(*) FROM menu_items WHERE image_path IS NOT NULL AND image_path <> ""', [], 0
);

$pageTitle   = 'Manage Menu';
$activeAdmin = 'items';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Menu Items</h1>
        <p><?= count($items) ?> item(s) shown · <?= count($categories) ?> categories</p>
    </div>
    <?php if ($editing): ?>
        <a class="btn btn-ghost" href="<?= e(url('/admin/items.php')) ?>">+ Add a new item instead</a>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/admin_nav.php'; ?>

<div class="two-col">

    <!-- ------------------------------------------------------- the list -->
    <div class="card">
        <form class="toolbar" method="get" action="<?= e(url('/admin/items.php')) ?>">
            <div class="grow">
                <input type="search" name="q" value="<?= e($term) ?>" placeholder="Search items…">
            </div>
            <?php if ($filterCat > 0): ?>
                <input type="hidden" name="cat" value="<?= (int) $filterCat ?>">
            <?php endif; ?>
            <button class="btn btn-primary" type="submit">Search</button>
            <?php if ($term !== '' || $filterCat > 0): ?>
                <a class="btn btn-ghost" href="<?= e(url('/admin/items.php')) ?>">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($demoCount > 0): ?>
            <div class="alert alert-warn" style="margin-bottom:14px;">
                <strong><?= $demoCount ?> demo item<?= $demoCount === 1 ? '' : 's' ?> on this menu.</strong>
                They ship with stock photographs from Wikimedia Commons, not photos of
                your own cooking. Replace the photos, or clear the samples out and build
                your real menu.
                <form method="post" action="<?= e(url('/admin/items.php')) ?>" data-dirty-check style="margin-top:9px;" data-confirm="Delete all <?= $demoCount ?> demo items, along with their sample orders? This cannot be undone.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="purge_demo">
                    <button class="btn btn-danger btn-sm" type="submit">
                        Remove all demo data
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="cat-pills">
            <a href="<?= e(url('/admin/items.php')) ?>" class="<?= $filterCat === 0 ? 'active' : '' ?>">All</a>
            <?php foreach ($categories as $category): ?>
                <a href="<?= e(url('/admin/items.php?cat=' . (int) $category['id'])) ?>"
                   class="<?= $filterCat === (int) $category['id'] ? 'active' : '' ?>">
                    <?= e($category['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($items === []): ?>
            <div class="empty" style="padding:30px 16px;">
                <span class="big"><?= icon('plate', 46) ?></span>
                <h3>Nothing here</h3>
                <p>Use the form to add your first item.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="num">Price</th>
                            <th>Stock &amp; availability</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="cell-main">
                                <div class="item-cell">
                                    <?php if (!empty($item['image_path'])): ?>
                                        <img class="thumb" loading="lazy" width="160" height="160"
                                             src="<?= e(url(menu_photo_url($item['image_path'], 'tiny'))) ?>"
                                             alt="">
                                    <?php else: ?>
                                        <span class="thumb-empty" title="No photo yet"><?= item_icon($item['icon'], 22) ?></span>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?= e($item['name']) ?></strong>
                                        <?php if ((int) $item['is_demo'] === 1): ?>
                                            <span class="badge badge-off" title="Sample data">demo</span>
                                        <?php endif; ?>
                                        <div class="small muted">
                                            <?= item_icon($item['category_icon'], 14) ?> <?= e($item['category_name']) ?>
                                            · <?= (int) $item['prep_minutes'] ?> min
                                            <?php if (empty($item['image_path'])): ?>
                                                · no photo
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="num nowrap" data-label="Price"><?= e(money($item['price'])) ?></td>
                            <td data-label="Stock &amp; availability">
                                <form method="post" action="<?= e(url('/admin/items.php')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="quick">
                                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                    <input class="qty-input" type="number" name="stock" min="0"
                                           value="<?= (int) $item['stock'] ?>" aria-label="Stock">
                                    <select name="is_available" class="select-compact">
                                        <option value="1" <?= (int) $item['is_available'] === 1 ? 'selected' : '' ?>>On sale</option>
                                        <option value="0" <?= (int) $item['is_available'] === 0 ? 'selected' : '' ?>>Off menu</option>
                                    </select>
                                    <button class="btn btn-ghost btn-sm" type="submit">Save</button>
                                </form>
                            </td>
                            <td class="num nowrap cell-actions">
                                <a class="btn btn-ghost btn-sm"
                                   href="<?= e(url('/admin/items.php?edit=' . (int) $item['id'])) ?>">Edit</a>
                                <form method="post" action="<?= e(url('/admin/items.php')) ?>" style="display:inline;" data-confirm="Delete “<?= e($item['name']) ?>”? Past orders keep their copy of the name and price.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ------------------------------------------------------ add / edit -->
    <div class="card">
        <h2><?= $editing ? 'Edit item' : 'Add a new item' ?></h2>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php
            // Defaults for a brand-new item.
            $v = $editing ?? [
                'id' => 0, 'name' => '', 'description' => '', 'price' => '',
                'icon' => 'plate', 'category_id' => $categoryIds[0] ?? 0,
                'stock' => 20, 'prep_minutes' => 10, 'is_available' => 1,
            ];
        ?>

        <?php // enctype is required for the photo upload to arrive at all ?>
        <form method="post" action="<?= e(url('/admin/items.php')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">

            <div class="field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="<?= e($v['name']) ?>"
                       maxlength="120" required placeholder="Chicken Adobo Rice">
            </div>

            <div class="field">
                <label for="photo">Photo of this dish</label>

                <?php if (!empty($v['image_path'])): ?>
                    <div class="photo-preview">
                        <img src="<?= e(url(menu_photo_url($v['image_path'], 'tiny'))) ?>"
                             alt="Current photo of <?= e($v['name'] ?: 'this item') ?>">
                        <div class="meta">
                            <?php if (!empty($v['is_demo'])): ?>
                                <strong>Demo photo</strong>
                                This is a stock photograph, not your canteen's cooking.
                            <?php else: ?>
                                <strong>Current photo</strong>
                            <?php endif; ?>
                            <?php if (!empty($v['image_credit'])): ?>
                                <br><?= e($v['image_credit']) ?>
                            <?php endif; ?>
                            <?php if (!empty($v['image_source'])): ?>
                                <br><a href="<?= e($v['image_source']) ?>" target="_blank"
                                       rel="noopener nofollow">View source</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif (empty($v['id'])): ?>
                    <p class="hint" style="margin:0 0 8px;">
                        New items start with no photo. Snap the real dish on your phone and
                        upload it — a real photo of your own cooking beats a stock image
                        every time.
                    </p>
                <?php endif; ?>

                <?php if (!function_exists('imagecreatefromjpeg')): ?>
                    <div class="alert alert-warn" style="margin:0 0 8px;">
                        Photo uploads are switched off: this PHP has no GD extension.
                        Install <span class="mono">php-gd</span> (it ships with the
                        Termux php package) and the upload box will appear.
                    </div>
                <?php else: ?>
                    <input type="file" id="photo" name="photo"
                           accept="image/jpeg,image/png,image/webp">
                <?php endif; ?>

                <div class="hint">
                    JPEG, PNG or WebP · at least 300×220 · up to 6 MB ·
                    resized automatically.
                    <?php if (!empty($v['image_path'])): ?>
                        Uploading a new one replaces the current photo, and removes any
                        third-party credit with it.
                    <?php endif; ?>
                </div>

                <?php if (!empty($v['image_path'])): ?>
                    <label style="font-weight:500; font-size:.85rem; margin-top:8px; display:block;">
                        <input type="checkbox" name="remove_photo" value="1" style="width:auto;">
                        Remove the photo and show the icon only
                    </label>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" maxlength="255"
                          placeholder="What is in it?"><?= e($v['description']) ?></textarea>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="price">Price (<?= e(CURRENCY) ?>)</label>
                    <input type="number" id="price" name="price" step="0.01" min="0"
                           value="<?= e($v['price']) ?>" required>
                </div>
                  <?= icon_picker('icon', $v['icon'], 'Icon') ?>
            </div>

            <div class="field">
                <label for="category_id">Category</label>
                <select id="category_id" name="category_id" required>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>"
                            <?= (int) $v['category_id'] === (int) $category['id'] ? 'selected' : '' ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="stock">Stock on hand</label>
                    <input type="number" id="stock" name="stock" min="0"
                           value="<?= (int) $v['stock'] ?>" required>
                </div>
                <div class="field">
                    <label for="prep_minutes">Prep minutes</label>
                    <input type="number" id="prep_minutes" name="prep_minutes" min="1" max="120"
                           value="<?= (int) $v['prep_minutes'] ?>" required>
                </div>
            </div>

            <div class="field">
                <label for="is_available">Availability</label>
                <select id="is_available" name="is_available">
                    <option value="1" <?= (int) $v['is_available'] === 1 ? 'selected' : '' ?>>On sale</option>
                    <option value="0" <?= (int) $v['is_available'] === 0 ? 'selected' : '' ?>>Off menu (hidden from students)</option>
                </select>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">
                    <?= $editing ? 'Save changes' : 'Add to menu' ?>
                </button>
                <?php if ($editing): ?>
                    <a class="btn btn-ghost" href="<?= e(url('/admin/items.php')) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
