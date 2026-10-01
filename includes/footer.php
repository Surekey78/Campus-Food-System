<?php
/**
 * includes/footer.php  —  shared page bottom
 *
 * On phones the main navigation lives down here as a tab bar: a thumb
 * reaches the bottom of the screen far more easily than the top, and it
 * keeps the top bar small enough to leave room for actual content.
 */
$script  = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
$here    = basename($script);
$inAdmin = str_contains($script, '/admin/');
$tabCart = cart_count();

/** Mark the tab matching the page the visitor is on. */
$tabIs = static function (string $name) use ($here, $inAdmin): string {
    return $name === ($inAdmin ? 'admin:' . $here : $here) ? 'active' : '';
};
?>
</main>

<footer class="footer">
    <div class="wrap">
        <p>
            <strong><?= e(APP_NAME) ?></strong> &middot; Campus canteen ordering system
            &middot; Built with PHP &amp; MariaDB (mysqli)
        </p>
        <p class="muted small">
            Demo project &mdash; orders are not sent to a real kitchen.
        </p>
    </div>
</footer>

<?= icon_sprite() ?>

<nav class="tabbar" aria-label="Main navigation">
    <a href="<?= e(url('/index.php')) ?>" class="<?= $tabIs('index.php') ?>">
        <span class="tab-ico"><?= icon("menu", 24) ?></span>Menu
    </a>

    <a href="<?= e(url('/cart.php')) ?>" class="<?= $tabIs('cart.php') ?>">
        <span class="tab-ico"><?= icon("cart", 24) ?></span>Cart
        <?php if ($tabCart > 0): ?>
            <span class="tab-badge"><?= (int) $tabCart ?></span>
        <?php endif; ?>
    </a>

    <?php if (is_logged_in()): ?>
        <a href="<?= e(url('/orders.php')) ?>" class="<?= $tabIs('orders.php') ?>">
            <span class="tab-ico"><?= icon("receipt", 24) ?></span>Orders
        </a>
    <?php endif; ?>

    <?php if (is_admin()): ?>
        <a href="<?= e(url('/admin/index.php')) ?>"
           class="<?= $inAdmin ? 'active' : '' ?>">
            <span class="tab-ico"><?= icon("kitchen", 24) ?></span>Kitchen
        </a>
    <?php endif; ?>

    <?php if (is_logged_in()): ?>
        <a href="<?= e(url('/account.php')) ?>" class="<?= $tabIs('account.php') ?>">
            <span class="tab-ico"><?= icon("user", 24) ?></span>Account
        </a>
    <?php else: ?>
        <a href="<?= e(url('/login.php')) ?>" class="<?= $tabIs('login.php') ?>">
            <span class="tab-ico"><?= icon("user", 24) ?></span>Sign in
        </a>
    <?php endif; ?>
</nav>

</body>
</html>
