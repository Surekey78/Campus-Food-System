<?php
/**
 * public/order.php  —  one order: receipt, pickup info and live status
 * ?code=CF-000123
 * A student may only open their own orders; an admin may open any.
 */
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
$code = trim((string) ($_GET['code'] ?? ''));

$sql    = 'SELECT o.*, u.name AS customer_name, u.email AS customer_email,
                  u.student_id, u.phone
             FROM orders o
             JOIN users u ON u.id = o.user_id
            WHERE ';
$params = [];

if ($code !== '') {
    $sql     .= 'o.order_code = ?';
    $params[] = $code;
} elseif (ctype_digit((string) ($_GET['id'] ?? ''))) {
    $sql     .= 'o.id = ?';
    $params[] = (int) $_GET['id'];
} else {
    flash('Order not found.', 'error');
    redirect('/orders.php');
}

$order = db_one($sql, $params);

// Only the owner (or any admin) may look at it.
if (!$order || (!is_admin() && (int) $order['user_id'] !== (int) $user['id'])) {
    flash('Order not found.', 'error');
    redirect('/orders.php');
}

$items = db_all(
    'SELECT * FROM order_items WHERE order_id = ? ORDER BY id',
    [(int) $order['id']]
);

// ------------------------------------------------------------ status steps
$steps = [
    'pending'   => 'Order received by the canteen',
    'preparing' => 'Being cooked / prepared',
    'ready'     => 'Ready for pickup at the counter',
    'completed' => 'Collected — enjoy your meal!',
];
$currentIndex = array_search($order['status'], array_keys($steps), true);
$cancelled    = $order['status'] === 'cancelled';

$pageTitle = 'Order ' . $order['order_code'];
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Order <?= e($order['order_code']) ?> <?= status_badge($order['status']) ?></h1>
        <p>Placed <?= e(nice_date($order['placed_at'])) ?> · <?= e(time_ago($order['placed_at'])) ?></p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <?php if (is_admin()): ?>
            <a class="btn btn-ghost" href="<?= e(url('/admin/index.php')) ?>">Kitchen board</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/orders.php')) ?>">← My orders</a>
        <a class="btn btn-primary" href="<?= e(url('/index.php')) ?>">Order again</a>
    </div>
</div>

<div class="two-col">

    <div>
        <!-- ---------------------------------------------------- receipt -->
        <div class="card">
            <div class="card-head">
                <h2>Receipt</h2>
                <span class="pill-note"><?= $order['payment_method'] === 'wallet' ? 'Paid by e-wallet' : 'Pay cash on pickup' ?></span>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="num">Unit</th>
                            <th class="num">Qty</th>
                            <th class="num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><strong><?= item_icon($item['item_icon'], 16) ?> <?= e($item['item_name']) ?></strong></td>
                            <td class="num nowrap"><?= e(money($item['unit_price'])) ?></td>
                            <td class="num"><?= (int) $item['quantity'] ?></td>
                            <td class="num nowrap"><strong><?= e(money($item['line_total'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="right">Subtotal</td>
                            <td class="num"><?= e(money($order['subtotal'])) ?></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="right">Service fee</td>
                            <td class="num"><?= e(money($order['service_fee'])) ?></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="right">Total</td>
                            <td class="num" style="color:var(--brand-dark); font-size:1.05rem;">
                                <?= e(money($order['total'])) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if ($order['notes']): ?>
                <div class="alert alert-info" style="margin:16px 0 0;">
                    <strong>Your note:</strong> <?= e($order['notes']) ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ------------------------------------------------- collecting -->
        <div class="card">
            <h2>Collecting</h2>
            <div class="grid-2">
                <div>
                    <div class="small muted">Name</div>
                    <div><strong><?= e($order['customer_name']) ?></strong></div>
                </div>
                <div>
                    <div class="small muted">Student ID</div>
                    <div><?= e($order['student_id'] ?: '—') ?></div>
                </div>
                <div>
                    <div class="small muted">Pickup time</div>
                    <div><strong><?= e($order['pickup_slot']) ?></strong></div>
                </div>
                <div>
                    <div class="small muted">Contact</div>
                    <div><?= e($order['phone'] ?: $order['customer_email']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------------- status -->
    <div class="card">
        <h2>Status</h2>

        <?php if ($cancelled): ?>
            <div class="alert alert-error" style="margin:0;">
                This order was cancelled. Any e-wallet payment has been refunded.
            </div>
        <?php else: ?>
            <ul class="timeline">
                <?php $i = 0; foreach ($steps as $key => $label): ?>
                    <?php
                        $done = $currentIndex !== false && $i <= $currentIndex;
                        $now  = $i === $currentIndex;
                    ?>
                    <li>
                        <span class="dot"><?= status_icon($key) ?></span>
                        <span class="<?= $done ? 'done' : 'todo' ?>">
                            <?= e($label) ?>
                            <?php if ($now): ?>
                                <span class="pill-note" style="margin-left:4px;">now</span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php $i++; endforeach; ?>
            </ul>

            <p class="small muted" style="margin-top:14px;">
                Show <strong><?= e($order['order_code']) ?></strong> (or your student ID) at
                the canteen counter. The kitchen updates this page as your order moves along.
            </p>
        <?php endif; ?>

        <hr style="border:0;border-top:1px dashed var(--line); margin:16px 0;">
        <ul class="list-clean small">
            <li><span class="muted">Items</span><span><?= (int) $order['item_count'] ?></span></li>
            <li><span class="muted">Payment</span><span><?= $order['payment_method'] === 'wallet' ? 'E-wallet' : 'Cash' ?></span></li>
            <li><span class="muted">Last update</span><span><?= e(time_ago($order['updated_at'])) ?></span></li>
        </ul>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
