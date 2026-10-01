<?php
/**
 * public/cart.php  —  view / edit the shopping cart
 * The cart itself lives in the session; the item details (price, stock)
 * are always re-read from the database so the totals stay honest.
 */
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $itemId = (int) ($_POST['item_id'] ?? 0);

    if ($action === 'update') {
        $current = (int) (cart()[$itemId] ?? 0);
        $step    = (string) ($_POST['step'] ?? '');

        // The -/+ buttons send a step; the number field sends a value.
        $qty = $step === 'inc' ? $current + 1
             : ($step === 'dec' ? $current - 1 : (int) ($_POST['qty'] ?? 0));

        $row = db_one('SELECT stock FROM menu_items WHERE id = ?', [$itemId]);
        $max = $row ? max(0, (int) $row['stock']) : 0;

        if ($qty > $max) {
            $qty = $max;
            flash($max > 0
                ? 'The kitchen only has ' . $max . ' left, so that is as many as you can order.'
                : 'That item just sold out.', 'warn');
        } else {
            flash('Cart updated.');
        }

        cart_set($itemId, max(0, $qty));
    } elseif ($action === 'remove') {
        cart_remove($itemId);
        flash('Item removed from your cart.', 'warn');
    } elseif ($action === 'clear') {
        cart_clear();
        flash('Cart emptied.', 'warn');
    }

    redirect('/cart.php');
}

$lines    = cart_lines();
$subtotal = cart_subtotal();
$fee      = $lines === [] ? 0.0 : (float) SERVICE_FEE;

$pageTitle = 'Your Cart';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Your Cart</h1>
        <p><?= cart_count() ?> item<?= cart_count() === 1 ? '' : 's' ?> waiting to be ordered.</p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/index.php')) ?>">← Keep browsing</a>
</div>

<?php if ($lines === []): ?>

    <div class="empty">
        <span class="big"><?= icon('cart', 46) ?></span>
        <h3>Your cart is empty</h3>
        <p>Add something delicious from the menu to get started.</p>
        <a class="btn btn-primary" href="<?= e(url('/index.php')) ?>">Browse the menu</a>
    </div>

<?php else: ?>

    <div class="two-col">
        <div class="card">
            <div class="card-head">
                <h2>Items</h2>
                <form method="post" action="<?= e(url('/cart.php')) ?>" data-confirm="Empty the whole cart?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="clear">
                    <button class="btn btn-danger btn-sm" type="submit">Empty cart</button>
                </form>
            </div>

            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="num">Price</th>
                            <th class="num">Qty</th>
                            <th class="num">Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lines as $line): ?>
                        <?php
                            $short = (int) $line['stock'] < (int) $line['quantity'];
                            $gone  = (int) $line['is_available'] !== 1 || (int) $line['stock'] < 1;
                        ?>
                        <?php $thumb = menu_photo_url($line['image_path'] ?? null, 'tiny'); ?>
                        <tr>
                            <td class="cell-main">
                                <div class="item-cell">
                                    <?php if ($thumb !== null): ?>
                                        <img class="thumb" src="<?= e(url($thumb)) ?>" alt=""
                                             width="160" height="120" loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <span class="thumb-empty" aria-hidden="true"><?= item_icon($line['icon'], 22) ?></span>
                                    <?php endif; ?>
                                    <div class="min0">
                                        <strong><?= e($line['name']) ?></strong>
                                        <div class="small muted"><?= e(money($line['price'])) ?> each</div>
                                        <?php if ($gone): ?>
                                            <div class="small stock-out">No longer available — please remove it.</div>
                                        <?php elseif ($short): ?>
                                            <div class="small stock-low">Only <?= (int) $line['stock'] ?> in stock; please lower the quantity.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="num nowrap" data-label="Price"><?= e(money($line['price'])) ?></td>
                            <td class="num" data-label="Qty">
                                <form method="post" action="<?= e(url('/cart.php')) ?>" class="stepper">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="item_id" value="<?= (int) $line['id'] ?>">

                                    <button class="btn btn-ghost step-btn" type="submit"
                                            name="step" value="dec" aria-label="One <?= e($line['name']) ?> less">−</button>

                                    <input class="qty-input" type="number" name="qty"
                                           value="<?= (int) $line['quantity'] ?>" min="0"
                                           max="<?= max(1, (int) $line['stock']) ?>"
                                           inputmode="numeric" aria-label="Quantity of <?= e($line['name']) ?>"
                                           data-autosubmit>

                                    <button class="btn btn-ghost step-btn" type="submit"
                                            name="step" value="inc" aria-label="One <?= e($line['name']) ?> more">+</button>
                                </form>
                            </td>
                            <td class="num nowrap" data-label="Line total"><strong><?= e(money($line['line_total'])) ?></strong></td>
                            <td class="num cell-actions">
                                <form method="post" action="<?= e(url('/cart.php')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="item_id" value="<?= (int) $line['id'] ?>">
                                    <button class="btn btn-danger btn-sm" type="submit"
                                            title="Remove <?= e($line['name']) ?>">
                                        <?= icon('x', 16) ?><span class="rm-label"> Remove</span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card summary-sticky">
            <h2>Order Summary</h2>
            <div class="totals">
                <div class="row"><span>Subtotal</span><span><?= e(money($subtotal)) ?></span></div>
                <div class="row"><span>Service fee</span><span><?= e(money($fee)) ?></span></div>
                <div class="row grand"><span>Total</span><span><?= e(money($subtotal + $fee)) ?></span></div>
            </div>

            <div style="margin-top:18px; display:grid; gap:9px;">
                <?php if (is_logged_in()): ?>
                    <a class="btn btn-primary btn-block" href="<?= e(url('/checkout.php')) ?>">
                        Proceed to checkout
                    </a>
                <?php else: ?>
                    <a class="btn btn-primary btn-block" href="<?= e(url('/login.php')) ?>">
                        Sign in to check out
                    </a>
                    <p class="small muted center" style="margin:0;">
                        New here? <a href="<?= e(url('/register.php')) ?>">Create an account</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
