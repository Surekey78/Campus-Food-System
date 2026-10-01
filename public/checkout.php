<?php
/**
 * public/checkout.php  —  confirm pickup details and place the order
 *
 * The whole order is written inside ONE mysqli transaction:
 *   lock the menu rows -> re-check stock and prices -> insert the order
 *   -> insert the line items -> decrement stock -> (wallet: deduct balance)
 *   -> commit.
 * If anything fails, everything rolls back, so the database can never
 * end up with half an order or stock that was never really sold.
 */
require_once __DIR__ . '/../includes/functions.php';

$user  = require_login();
$lines = cart_lines();

if ($lines === []) {
    flash('Your cart is empty — add something first.', 'warn');
    redirect('/index.php');
}

$slot    = 'ASAP';
$payment = 'cash';
$notes   = '';
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $slot    = (string) ($_POST['pickup_slot'] ?? 'ASAP');
    $payment = ($_POST['payment_method'] ?? 'cash') === 'wallet' ? 'wallet' : 'cash';
    $notes   = trim((string) ($_POST['notes'] ?? ''));

    if (!in_array($slot, $PICKUP_SLOTS, true)) {
        $slot = 'ASAP';
    }
    if (char_len($notes) > 240) {
        $errors[] = 'Notes must be 240 characters or fewer.';
    }

    $conn = db();
    $conn->begin_transaction();

    try {
        /* ---------- 1. Lock the rows we are about to sell ----------
           FOR UPDATE stops two students from buying the last siopao at
           the exact same moment.                                      */
        $placeholders = implode(',', array_fill(0, count($lines), '?'));
        $locked = [];
        foreach (db_all(
            "SELECT id, name, icon, price, stock, is_available
               FROM menu_items WHERE id IN ($placeholders) FOR UPDATE",
            array_map(static fn ($l) => (int) $l['id'], $lines)
        ) as $row) {
            $locked[(int) $row['id']] = $row;
        }

        /* ---------- 2. Re-validate against the live data ---------- */
        $orderLines = [];
        $subtotal   = 0.0;

        foreach ($lines as $line) {
            $id   = (int) $line['id'];
            $qty  = (int) $line['quantity'];
            $live = $locked[$id] ?? null;

            if ($live === null) {
                $errors[] = $line['name'] . ' was removed from the menu.';
                continue;
            }
            if ((int) $live['is_available'] !== 1) {
                $errors[] = $live['name'] . ' is currently unavailable.';
                continue;
            }
            if ((int) $live['stock'] < $qty) {
                $errors[] = sprintf(
                    '%s: only %d left, you asked for %d.',
                    $live['name'], (int) $live['stock'], $qty
                );
                continue;
            }

            $price      = (float) $live['price'];      // live price wins
            $lineTotal  = round($price * $qty, 2);
            $subtotal  += $lineTotal;

            $orderLines[] = [
                'menu_item_id' => $id,
                'item_name'    => $live['name'],
                'item_icon'    => $live['icon'],
                'unit_price'   => $price,
                'quantity'     => $qty,
                'line_total'   => $lineTotal,
            ];
        }

        $subtotal = round($subtotal, 2);
        $fee      = (float) SERVICE_FEE;
        $total    = round($subtotal + $fee, 2);

        /* ---------- 3. Wallet balance check ---------- */
        if ($errors === [] && $payment === 'wallet') {
            $balance = (float) db_val('SELECT wallet FROM users WHERE id = ?', [$user['id']], 0);
            if ($balance < $total) {
                $errors[] = 'Not enough wallet balance: ' . money($balance)
                          . ' available, ' . money($total) . ' needed. '
                          . 'Top up at the cashier or pay with cash.';
            }
        }

        if ($errors !== []) {
            $conn->rollback();      // release the row locks
        } else {
            /* ---------- 4. Insert the order header ---------- */
            // Temporary unique code first, because the final code is
            // derived from the auto-increment id we don't know yet.
            $tempCode = 'TMP-' . bin2hex(random_bytes(6));

            db_exec(
                'INSERT INTO orders
                    (order_code, user_id, status, payment_method, pickup_slot, notes,
                     item_count, subtotal, service_fee, total)
                 VALUES (?, ?, "pending", ?, ?, ?, ?, ?, ?, ?)',
                [
                    $tempCode,
                    (int) $user['id'],
                    $payment,
                    $slot,
                    $notes !== '' ? $notes : null,
                    array_sum(array_column($orderLines, 'quantity')),
                    $subtotal,
                    $fee,
                    $total,
                ]
            );

            $orderId = db_insert_id();
            $code    = 'CF-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);

            db_exec('UPDATE orders SET order_code = ? WHERE id = ?', [$code, $orderId]);

            /* ---------- 5. Insert the line items + cut stock ---------- */
            foreach ($orderLines as $row) {
                db_exec(
                    'INSERT INTO order_items
                        (order_id, menu_item_id, item_name, item_icon,
                         unit_price, quantity, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $orderId, $row['menu_item_id'], $row['item_name'], $row['item_icon'],
                        $row['unit_price'], $row['quantity'], $row['line_total'],
                    ]
                );

                db_exec(
                    'UPDATE menu_items
                        SET stock = stock - ?,
                            is_available = IF(stock - ? > 0, 1, 0)
                      WHERE id = ?',
                    [$row['quantity'], $row['quantity'], $row['menu_item_id']]
                );
            }

            /* ---------- 6. Pay from the wallet, if asked ---------- */
            if ($payment === 'wallet') {
                // The wallet >= ? guard makes the deduction atomic: if the
                // balance moved between the check above and here, this
                // update matches zero rows and we roll back.
                $paid = db_exec(
                    'UPDATE users SET wallet = wallet - ? WHERE id = ? AND wallet >= ?',
                    [$total, (int) $user['id'], $total]
                );

                if ($paid !== 1) {
                    throw new RuntimeException('Wallet balance changed during checkout.');
                }
            }

            $conn->commit();

            cart_clear();
            flash('Order ' . $code . ' placed! Track it under "My Orders".');

            redirect('/order.php?code=' . urlencode($code));
        }
    } catch (Throwable $ex) {
        $conn->rollback();
        $errors[] = 'Sorry, we could not place the order. ' . $ex->getMessage();
    }
}

// ------------------------------------------------------------------ display
$subtotal   = 0.0;
foreach ($lines as $line) {
    $subtotal += $line['line_total'];
}
$subtotal = round($subtotal, 2);
$total    = round($subtotal + (float) SERVICE_FEE, 2);

$pageTitle = 'Checkout';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Checkout</h1>
        <p>Pick a pickup time and confirm your order.</p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/cart.php')) ?>">← Back to cart</a>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="two-col">

    <div class="card">
        <h2>Pickup &amp; Payment</h2>

        <form method="post" action="<?= e(url('/checkout.php')) ?>">
            <?= csrf_field() ?>

            <div class="grid-2">
                <div class="field">
                    <label for="pickup_slot">Pickup time</label>
                    <select id="pickup_slot" name="pickup_slot">
                        <?php foreach ($PICKUP_SLOTS as $option): ?>
                            <option value="<?= e($option) ?>" <?= $slot === $option ? 'selected' : '' ?>>
                                <?= e($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">“ASAP” means as soon as the kitchen can finish it.</div>
                </div>

                <div class="field">
                    <label for="payment_method">Payment</label>
                    <select id="payment_method" name="payment_method">
                        <option value="cash" <?= $payment === 'cash' ? 'selected' : '' ?>>
                            Cash at the counter
                        </option>
                        <option value="wallet" <?= $payment === 'wallet' ? 'selected' : '' ?>>
                            Campus e-wallet (<?= e(money($user['wallet'])) ?> balance)
                        </option>
                    </select>
                    <div class="hint">
                        Wallet orders are charged immediately. Cash is paid on pickup.
                    </div>
                </div>
            </div>

            <div class="field">
                <label for="notes">Notes for the canteen (optional)</label>
                <textarea id="notes" name="notes" maxlength="240"
                          placeholder="e.g. less rice, extra sauce, no onions…"><?= e($notes) ?></textarea>
                <div class="hint">Up to 240 characters.</div>
            </div>

            <div class="field">
                <label>Collecting as</label>
                <p style="margin:0;">
                    <strong><?= e($user['name']) ?></strong>
                    <?php if ($user['student_id']): ?>
                        · ID <?= e($user['student_id']) ?>
                    <?php endif; ?>
                    <br><span class="small muted"><?= e($user['email']) ?></span>
                </p>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Place order · <?= e(money($total)) ?></button>
                <a class="btn btn-ghost" href="<?= e(url('/cart.php')) ?>">Cancel</a>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Order Summary</h2>

        <ul class="list-clean">
            <?php foreach ($lines as $line): ?>
                <li>
                    <span><?= item_icon($line['icon'], 16) ?> <?= e($line['name']) ?>
                        <span class="muted">×<?= (int) $line['quantity'] ?></span></span>
                    <span class="nowrap"><?= e(money($line['line_total'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="totals" style="margin-top:12px;">
            <div class="row"><span>Subtotal</span><span><?= e(money($subtotal)) ?></span></div>
            <div class="row"><span>Service fee</span><span><?= e(money(SERVICE_FEE)) ?></span></div>
            <div class="row grand"><span>Total</span><span><?= e(money($total)) ?></span></div>
        </div>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
