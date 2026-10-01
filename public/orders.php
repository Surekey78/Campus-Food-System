<?php
/**
 * public/orders.php  —  the student's order history + live status
 */
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();

// -------------------------------------------------- cancel a pending order
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $orderId = (int) ($_POST['order_id'] ?? 0);

    $order = db_one(
        'SELECT id, order_code, status, payment_method, total
           FROM orders WHERE id = ? AND user_id = ?',
        [$orderId, (int) $user['id']]
    );

    if (!$order) {
        flash('That order does not exist.', 'error');
    } elseif (!in_array($order['status'], ['pending', 'preparing'], true)) {
        flash('An order that is already ' . $order['status'] . ' can no longer be cancelled.', 'warn');
    } else {
        // Cancelling restores the stock and refunds a wallet payment.
        $conn = db();
        $conn->begin_transaction();

        try {
            db_exec('UPDATE orders SET status = "cancelled" WHERE id = ?', [$orderId]);

            $items = db_all(
                'SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?',
                [$orderId]
            );
            foreach ($items as $item) {
                if ($item['menu_item_id'] !== null) {
                    db_exec(
                        'UPDATE menu_items
                            SET stock = stock + ?,
                                is_available = IF(stock + ? > 0, 1, 0)
                          WHERE id = ?',
                        [(int) $item['quantity'], (int) $item['quantity'], (int) $item['menu_item_id']]
                    );
                }
            }

            if ($order['payment_method'] === 'wallet') {
                db_exec('UPDATE users SET wallet = wallet + ? WHERE id = ?',
                        [$order['total'], (int) $user['id']]);
            }

            $conn->commit();
            flash($order['order_code'] . ' cancelled'
                . ($order['payment_method'] === 'wallet' ? ' and the amount refunded to your wallet.' : '.'),
                'warn');
        } catch (Throwable $ex) {
            $conn->rollback();
            flash('Could not cancel that order. ' . $ex->getMessage(), 'error');
        }
    }

    redirect('/orders.php');
}

// ------------------------------------------------------------------- list
$status = (string) ($_GET['status'] ?? '');
$where  = ['o.user_id = ?'];
$params = [(int) $user['id']];

if (in_array($status, order_statuses(), true)) {
    $where[]  = 'o.status = ?';
    $params[] = $status;
}

$orders = db_all(
    'SELECT o.id, o.order_code, o.status, o.payment_method, o.pickup_slot,
            o.item_count, o.total, o.placed_at,
            (SELECT GROUP_CONCAT(CONCAT(oi.item_icon, " ", oi.item_name) SEPARATOR ", ")
               FROM order_items oi WHERE oi.order_id = o.id) AS item_summary
       FROM orders o
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY o.placed_at DESC
      LIMIT 50',
    $params
);

$spent = (float) db_val(
    'SELECT COALESCE(SUM(total), 0) FROM orders WHERE user_id = ? AND status <> "cancelled"',
    [(int) $user['id']],
    0
);

$pageTitle = 'My Orders';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>My Orders</h1>
        <p>Track what you ordered and where the kitchen is up to.</p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/index.php')) ?>">+ New order</a>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="label">Orders placed</div>
        <div class="value"><?= count($orders) ?></div>
        <div class="note">showing latest 50</div>
    </div>
    <div class="stat">
        <div class="label">Lifetime spend</div>
        <div class="value"><?= e(money($spent)) ?></div>
        <div class="note">excludes cancelled orders</div>
    </div>
    <div class="stat">
        <div class="label">Wallet balance</div>
        <div class="value"><?= e(money($user['wallet'])) ?></div>
        <div class="note">campus e-wallet</div>
    </div>
</div>

<div class="cat-pills">
    <a href="<?= e(url('/orders.php')) ?>" class="<?= $status === '' ? 'active' : '' ?>">All</a>
    <?php foreach (order_statuses() as $s): ?>
        <a href="<?= e(url('/orders.php?status=' . $s)) ?>"
           class="<?= $status === $s ? 'active' : '' ?>"><?= e(status_label($s)) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($orders === []): ?>
    <div class="empty">
        <span class="big"><?= icon('receipt', 46) ?></span>
        <h3>No orders here yet</h3>
        <p>Order something from the canteen and it will show up in this list.</p>
        <a class="btn btn-primary" href="<?= e(url('/index.php')) ?>">Go to menu</a>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table table--stack orders-list">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Items</th>
                        <th>Pickup</th>
                        <th>Status</th>
                        <th class="num">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td class="cell-main">
                            <div class="order-head">
                                <a class="mono" href="<?= e(url('/order.php?code=' . urlencode($order['order_code']))) ?>">
                                    <strong><?= e($order['order_code']) ?></strong>
                                </a>
                                <?= status_badge($order['status']) ?>
                            </div>
                            <div class="small muted"><?= e(nice_date($order['placed_at'])) ?></div>
                        </td>
                        <td data-label="Items">
                            <span class="small"><?= e($order['item_summary'] ?: '—') ?></span>
                            <span class="small muted"><?= (int) $order['item_count'] ?> item(s) ·
                                <?= $order['payment_method'] === 'wallet' ? 'e-wallet' : 'cash' ?></span>
                        </td>
                        <td class="nowrap" data-label="Pickup"><?= e($order['pickup_slot']) ?></td>
                        <td data-label="Status"><?= status_badge($order['status']) ?></td>
                        <td class="num nowrap" data-label="Total"><strong><?= e(money($order['total'])) ?></strong></td>
                        <td class="num nowrap cell-actions">
                            <a class="btn btn-ghost btn-sm"
                               href="<?= e(url('/order.php?code=' . urlencode($order['order_code']))) ?>">View</a>

                            <?php if (in_array($order['status'], ['pending', 'preparing'], true)): ?>
                                <form method="post" action="<?= e(url('/orders.php')) ?>" style="display:inline;" data-confirm="Cancel <?= e($order['order_code']) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
