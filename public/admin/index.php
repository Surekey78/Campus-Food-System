<?php
/**
 * public/admin/index.php  —  canteen dashboard + live kitchen board
 *
 * This is where staff move an order along:
 *   pending -> preparing -> ready -> completed   (or cancelled)
 * Cancelling puts the stock back and refunds an e-wallet payment.
 */
require_once __DIR__ . '/../../includes/functions.php';

$admin = require_admin();

// ------------------------------------------------------- status transition
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $orderId = (int) ($_POST['order_id'] ?? 0);
    $to      = (string) ($_POST['to'] ?? '');

    // Which moves are legal, and from which state.
    $allowed = [
        'pending'   => ['preparing', 'cancelled'],
        'preparing' => ['ready',     'cancelled'],
        'ready'     => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    $order = db_one(
        'SELECT id, order_code, status, payment_method, user_id, total
           FROM orders WHERE id = ?',
        [$orderId]
    );

    if (!$order) {
        flash('Order not found.', 'error');
    } elseif (!in_array($to, $allowed[$order['status']] ?? [], true)) {
        flash(
            'Cannot move ' . $order['order_code'] . ' from '
            . status_label($order['status']) . ' to ' . status_label($to) . '.',
            'error'
        );
    } else {
        $conn = db();
        $conn->begin_transaction();

        try {
            db_exec('UPDATE orders SET status = ? WHERE id = ?', [$to, $orderId]);

            // Cancelling: give the stock back and refund wallet payments.
            if ($to === 'cancelled') {
                foreach (db_all(
                    'SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?',
                    [$orderId]
                ) as $item) {
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
                            [$order['total'], (int) $order['user_id']]);
                }
            }

            $conn->commit();
            flash($order['order_code'] . ' → ' . status_label($to)
                . ($to === 'cancelled' && $order['payment_method'] === 'wallet'
                    ? ' (amount refunded to the student\'s wallet)' : ''));
        } catch (Throwable $ex) {
            $conn->rollback();
            flash('Update failed: ' . $ex->getMessage(), 'error');
        }
    }

    redirect('/admin/index.php');
}

// ------------------------------------------------------------------ stats
$today = date('Y-m-d');

$stats = [
    'today_orders'  => (int) db_val(
        'SELECT COUNT(*) FROM orders WHERE DATE(placed_at) = ?', [$today], 0),
    'today_sales'   => (float) db_val(
        'SELECT COALESCE(SUM(total),0) FROM orders
          WHERE DATE(placed_at) = ? AND status <> "cancelled"', [$today], 0),
    'active'        => (int) db_val(
        'SELECT COUNT(*) FROM orders WHERE status IN ("pending","preparing","ready")', [], 0),
    'students'      => (int) db_val(
        'SELECT COUNT(*) FROM users WHERE role = "student"', [], 0),
    'menu_items'    => (int) db_val('SELECT COUNT(*) FROM menu_items', [], 0),
    'menu_off'      => (int) db_val(
        'SELECT COUNT(*) FROM menu_items WHERE is_available = 0 OR stock < 1', [], 0),
];

// --------------------------------------------------------- the order board
$board = db_all(
    'SELECT o.id, o.order_code, o.status, o.payment_method, o.pickup_slot, o.notes,
            o.total, o.item_count, o.placed_at,
            u.name AS customer_name, u.student_id, u.phone,
            (SELECT GROUP_CONCAT(CONCAT(oi.quantity, "× ", oi.item_name) SEPARATOR ", ")
               FROM order_items oi WHERE oi.order_id = o.id) AS item_summary
       FROM orders o
       JOIN users u ON u.id = o.user_id
      WHERE o.status IN ("pending","preparing","ready")
      ORDER BY FIELD(o.status, "ready", "preparing", "pending"), o.placed_at ASC
      LIMIT 60'
);

// ------------------------------------------------------------ low on stock
$lowStock = db_all(
    'SELECT id, name, icon, stock, is_available
       FROM menu_items
      WHERE stock <= 5 OR is_available = 0
      ORDER BY stock ASC, name
      LIMIT 10'
);

    // Each move carries an icon name from includes/icons.php.
    $nextMove = [
        'pending'   => ['to' => 'preparing', 'label' => 'Start cooking', 'icon' => 'kitchen'],
        'preparing' => ['to' => 'ready',     'label' => 'Mark ready',    'icon' => 'bell'],
        'ready'     => ['to' => 'completed', 'label' => 'Collected',     'icon' => 'check'],
    ];

$pageTitle = 'Canteen Dashboard';
$activeAdmin = 'dashboard';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Kitchen Dashboard</h1>
        <p>Signed in as <strong><?= e($admin['name']) ?></strong> · <?= e(nice_date(date('Y-m-d H:i:s'))) ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/items.php')) ?>">Manage menu</a>
</div>

<?php require __DIR__ . '/../../includes/admin_nav.php'; ?>

<?php if (!login_throttle_available()): ?>
    <div class="alert alert-warn">
        <strong>Sign-in throttling is switched off.</strong>
        This database was created before the feature existed, so the
        <code>login_attempts</code> table is missing and nobody is limited to
        5 failed passwords per 15 minutes. Nothing is broken — but anyone who
        wants in can guess passwords as fast as the server answers.
        Fix it from a terminal in the project folder:
        <code>php database/install.php --upgrade</code>
        It adds the table and keeps every order and account.
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat">
        <div class="label">Orders today</div>
        <div class="value"><?= $stats['today_orders'] ?></div>
        <div class="note">placed since midnight</div>
    </div>
    <div class="stat">
        <div class="label">Sales today</div>
        <div class="value"><?= e(money($stats['today_sales'])) ?></div>
        <div class="note">cancelled orders excluded</div>
    </div>
    <div class="stat">
        <div class="label">Live orders</div>
        <div class="value"><?= $stats['active'] ?></div>
        <div class="note">pending, cooking or ready</div>
    </div>
    <div class="stat">
        <div class="label">Registered students</div>
        <div class="value"><?= $stats['students'] ?></div>
        <div class="note"><?= $stats['menu_items'] ?> menu items on file</div>
    </div>
    <div class="stat">
        <div class="label">Needs attention</div>
        <div class="value"><?= $stats['menu_off'] ?></div>
        <div class="note">sold out or switched off</div>
    </div>
</div>

<div class="two-col">

    <!-- ----------------------------------------------------- order board -->
    <div class="card">
        <div class="card-head">
            <h2>Live Orders</h2>
            <span class="pill-note"><?= count($board) ?> in the queue</span>
        </div>

        <?php if ($board === []): ?>
            <div class="empty" style="padding:34px 18px;">
                <span class="big"><?= icon('check', 46) ?></span>
                <h3>All caught up</h3>
                <p>No pending, cooking or ready orders right now.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer &amp; items</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($board as $order): ?>
                        <tr>
                            <td class="cell-main nowrap">
                                <a class="mono" href="<?= e(url('/order.php?code=' . urlencode($order['order_code']))) ?>">
                                    <strong><?= e($order['order_code']) ?></strong>
                                </a>
                                <div class="small muted">
                                    <?= e($order['pickup_slot']) ?> ·
                                    <?= e(time_ago($order['placed_at'])) ?>
                                </div>
                                <div class="small"><?= e(money($order['total'])) ?>
                                    <?= $order['payment_method'] === 'wallet' ? '· paid' : '· cash' ?>
                                </div>
                            </td>
                            <td data-label="Customer">
                                <strong><?= e($order['customer_name']) ?></strong>
                                <?php if ($order['student_id']): ?>
                                    <span class="small muted">(<?= e($order['student_id']) ?>)</span>
                                <?php endif; ?>
                                <div class="small"><?= e($order['item_summary'] ?: '—') ?></div>
                                <?php if ($order['notes']): ?>
                                    <div class="small note-line" style="color:var(--amber);"><?= icon('note', 15) ?> <?= e($order['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status"><?= status_badge($order['status']) ?></td>
                            <td class="cell-actions">
                                <?php $move = $nextMove[$order['status']] ?? null; ?>
                                <?php if ($move): ?>
                                    <form method="post" action="<?= e(url('/admin/index.php')) ?>" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                        <input type="hidden" name="to" value="<?= e($move['to']) ?>">
                                        <button class="btn btn-primary btn-sm" type="submit">
                                            <?= icon($move['icon'], 16) ?> <?= e($move['label']) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" action="<?= e(url('/admin/index.php')) ?>" style="display:inline;" data-confirm="Cancel <?= e($order['order_code']) ?>? Stock will be returned and e-wallet payments refunded.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="to" value="cancelled">
                                    <button class="btn btn-danger btn-sm" type="submit">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- --------------------------------------------------- stock watchlist -->
    <div>
        <div class="card">
            <div class="card-head">
                <h2>Stock Watchlist</h2>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/items.php')) ?>">Edit items</a>
            </div>

            <?php if ($lowStock === []): ?>
                <p class="muted small" style="margin:0;">Every item has healthy stock.</p>
            <?php else: ?>
                <ul class="list-clean">
                    <?php foreach ($lowStock as $item): ?>
                        <li>
                            <span>
                                <?= item_icon($item['icon'], 15) ?> <?= e($item['name']) ?><br>
                                <span class="small">
                                    <?php if ((int) $item['is_available'] !== 1): ?>
                                        <span class="badge badge-off">Switched off</span>
                                    <?php elseif ((int) $item['stock'] < 1): ?>
                                        <span class="badge badge-cancelled">Sold out</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending"><?= (int) $item['stock'] ?> left</span>
                                    <?php endif; ?>
                                </span>
                            </span>
                            <a class="btn btn-ghost btn-sm"
                               href="<?= e(url('/admin/items.php?edit=' . (int) $item['id'])) ?>">Restock</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>How the flow works</h2>
            <ul class="timeline">
                <li><span class="dot"><?= icon("clock", 18) ?></span><span>Student places the order — stock is cut immediately.</span></li>
                <li><span class="dot"><?= icon("kitchen", 18) ?></span><span>Start cooking, then mark it ready.</span></li>
                <li><span class="dot"><?= icon("bell", 18) ?></span><span>The student's order page updates live.</span></li>
                <li><span class="dot"><?= icon("check", 18) ?></span><span>Mark collected once handed over.</span></li>
                <li><span class="dot"><?= icon("ban", 18) ?></span><span>Cancelling returns stock and refunds e-wallet payments.</span></li>
            </ul>
        </div>
    </div>

</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
