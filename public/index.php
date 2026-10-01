<?php
/**
 * public/index.php  —  the menu (home page)
 * Students browse the canteen menu, search/filter it and add items to
 * their cart. Admins see an "unavailable" flag on sold-out items.
 */
require_once __DIR__ . '/../includes/functions.php';

// ---------------------------------------------------------------- add to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $itemId = (int) ($_POST['item_id'] ?? 0);
    $qty    = max(1, (int) ($_POST['qty'] ?? 1));

    if (cart_add($itemId, $qty)) {
        $name = db_val('SELECT name FROM menu_items WHERE id = ?', [$itemId], 'Item');
        flash($name . ' added to your cart.');
    }

    // Send the student back to the same filter they were browsing.
    $returnTo = (string) ($_POST['return_to'] ?? '/index.php');
    if (!str_starts_with($returnTo, '/')) {       // only allow local paths
        $returnTo = '/index.php';
    }
    redirect($returnTo);
}

// ------------------------------------------------------------------- filters
$catId = isset($_GET['cat']) && ctype_digit((string) $_GET['cat']) ? (int) $_GET['cat'] : 0;
$term  = trim((string) ($_GET['q'] ?? ''));

$where  = ['c.is_active = 1'];
$params = [];

if ($catId > 0) {
    $where[]  = 'm.category_id = ?';
    $params[] = $catId;
}
if ($term !== '') {
    $where[]  = '(m.name LIKE ? OR m.description LIKE ?)';
    $params[] = '%' . $term . '%';
    $params[] = '%' . $term . '%';
}

$items = db_all(
    'SELECT m.id, m.name, m.description, m.price, m.icon, m.stock, m.is_available,
            m.prep_minutes, m.image_path, m.image_credit, m.image_source, m.is_demo,
            c.name AS category_name, c.icon AS category_icon
       FROM menu_items m
       JOIN categories c ON c.id = m.category_id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY c.sort_order, m.name',
    $params
);

$categories = db_all(
    'SELECT c.id, c.name, c.icon, COUNT(m.id) AS item_count
       FROM categories c
       LEFT JOIN menu_items m ON m.category_id = c.id AND m.is_available = 1
      WHERE c.is_active = 1
      GROUP BY c.id, c.name, c.icon, c.sort_order
      ORDER BY c.sort_order'
);

// Keep the current query string so "Add" returns to exactly this view.
$qs = $_SERVER['QUERY_STRING'] ?? '';
$returnTo = '/index.php' . ($qs !== '' ? '?' . $qs : '');

// Is this still a demo install? Used for the banner below.
$demoItems = (int) db_val(
    'SELECT COUNT(*) FROM menu_items WHERE is_demo = 1', [], 0
);

$pageTitle = 'Menu';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($demoItems > 0 && is_admin()): ?>
    <div class="alert alert-warn">
        <strong>Sample menu.</strong>
        <?= $demoItems ?> dish<?= $demoItems === 1 ? '' : 'es' ?> on this menu are demo content with
        stock photographs, not your canteen's real cooking.
        <a href="<?= e(url('/admin/items.php')) ?>">Manage menu items</a> when you are ready to go live —
        you can remove the samples in one click.
    </div>
<?php endif; ?>

<div class="page-head">
    <div>
        <h1>Today's Canteen Menu</h1>
        <p>Order ahead and skip the lunch queue.
            <?php if (is_logged_in()): ?>
                Signed in as <strong><?= e(current_user()['name']) ?></strong>.
            <?php else: ?>
                <a href="<?= e(url('/login.php')) ?>">Sign in</a> to place an order.
            <?php endif; ?>
        </p>
    </div>
</div>

<!-- search -->
<form class="toolbar" method="get" action="<?= e(url('/index.php')) ?>">
    <div class="grow">
        <input type="search" name="q" value="<?= e($term) ?>"
               placeholder="Search for adobo, halo-halo, iced tea…">
    </div>
    <?php if ($catId > 0): ?>
        <input type="hidden" name="cat" value="<?= (int) $catId ?>">
    <?php endif; ?>
    <button class="btn btn-primary" type="submit">Search</button>
    <?php if ($term !== '' || $catId > 0): ?>
        <a class="btn btn-ghost" href="<?= e(url('/index.php')) ?>">Clear</a>
    <?php endif; ?>
</form>

<!-- categories -->
<div class="cat-pills">
    <a href="<?= e(url('/index.php' . ($term !== '' ? '?q=' . urlencode($term) : ''))) ?>"
       class="<?= $catId === 0 ? 'active' : '' ?>"><?= icon('menu', 16) ?> All items</a>
    <?php foreach ($categories as $category): ?>
        <?php
            $qs = 'cat=' . (int) $category['id'];
            if ($term !== '') {
                $qs .= '&q=' . urlencode($term);
            }
        ?>
        <a href="<?= e(url('/index.php?' . $qs)) ?>" class="<?= $catId === (int) $category['id'] ? 'active' : '' ?>">
            <?= item_icon($category['icon'], 16) ?> <?= e($category['name']) ?>
            <span class="muted">(<?= (int) $category['item_count'] ?>)</span>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($items === []): ?>
    <div class="empty">
        <span class="big"><?= icon('search', 46) ?></span>
        <h3>No items matched</h3>
        <p>Try a different keyword or clear the filters.</p>
        <a class="btn btn-primary" href="<?= e(url('/index.php')) ?>">Show full menu</a>
    </div>
<?php else: ?>
    <div class="item-grid">
        <?php foreach ($items as $item): ?>
            <?php
                $soldOut = (int) $item['is_available'] !== 1 || (int) $item['stock'] < 1;
                $low     = !$soldOut && (int) $item['stock'] <= 5;
            ?>
            <article class="item-card <?= $soldOut ? 'is-out' : '' ?>">
                <?php
                    // 'card' resolves to the ~512px thumbnail when it exists,
                    // so a phone downloads ~42 KB per dish instead of ~115 KB.
                      $photo = menu_photo_url($item['image_path'], 'card');
                      // The 160px file is offered too: a phone on a slow
                      // connection (or a 1x screen) takes the 6 KB version.
                      $small = menu_photo_url($item['image_path'], 'tiny');
                  ?>
                <?php if ($photo !== null): ?>
                    <div class="item-photo">
                          <img src="<?= e(url($photo)) ?>"
                               <?php if ($small !== null && $small !== $photo): ?>
                                   srcset="<?= e(url($small)) ?> 160w, <?= e(url($photo)) ?> 512w"
                                   sizes="(max-width: 780px) 45vw, 232px"
                               <?php endif; ?>
                               alt="Photograph of <?= e($item['name']) ?>"
                               width="512" height="384"
                               loading="lazy" decoding="async">
                        <?php if ($soldOut): ?>
                            <span class="photo-flag">Sold out</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="item-top">
                    <span class="item-icon" aria-hidden="true"><?= item_icon($item['icon'], 24) ?></span>
                    <div>
                        <h3 class="item-name"><?= e($item['name']) ?></h3>
                        <span class="item-cat"><?= item_icon($item['category_icon'], 14) ?> <?= e($item['category_name']) ?></span>
                    </div>
                </div>

                <p class="item-desc"><?= e($item['description'] ?: 'Freshly prepared in the campus canteen.') ?></p>

                <div class="item-foot">
                    <div>
                        <div class="price"><?= e(money($item['price'])) ?></div>
                        <div class="stock-line">
                            <?php if ($soldOut): ?>
                                <span class="stock-out">Sold out</span>
                            <?php elseif ($low): ?>
                                <span class="stock-low">Only <?= (int) $item['stock'] ?> left</span>
                            <?php else: ?>
                                <?= (int) $item['stock'] ?> available ·
                            <?php endif; ?>
                            <?php if (!$soldOut): ?><?= (int) $item['prep_minutes'] ?> min<?php endif; ?>
                        </div>
                    </div>

                    <?php if ($soldOut): ?>
                        <button class="btn btn-ghost btn-sm is-disabled" disabled>Unavailable</button>
                    <?php else: ?>
                        <form class="inline-form" method="post" action="<?= e(url('/index.php')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                            <input class="qty-input" type="number" name="qty" value="1"
                                   min="1" max="<?= (int) $item['stock'] ?>" aria-label="Quantity">
                            <button class="btn btn-primary btn-sm" type="submit">Add</button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if (!empty($item['image_credit'])): ?>
                    <?php // CC BY / CC BY-SA require attribution, so the demo
                          // photos carry their author and licence on the card. ?>
                    <p class="photo-credit">
                        Photo:
                        <?php if (!empty($item['image_source'])): ?>
                            <a href="<?= e($item['image_source']) ?>" target="_blank"
                               rel="noopener nofollow"><?= e($item['image_credit']) ?></a>
                        <?php else: ?>
                            <?= e($item['image_credit']) ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
