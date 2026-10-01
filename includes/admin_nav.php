<?php
/**
 * includes/admin_nav.php  —  the sub-navigation shown on every admin page
 * Expects $activeAdmin to be set: 'dashboard' | 'items' | 'categories' | 'staff'
 */
$activeAdmin = $activeAdmin ?? 'dashboard';
?>
<div class="cat-pills">
    <a href="<?= e(url('/admin/index.php')) ?>"
       class="<?= $activeAdmin === 'dashboard' ? 'active' : '' ?>"><?= icon("gauge", 18) ?> Dashboard</a>
    <a href="<?= e(url('/admin/items.php')) ?>"
       class="<?= $activeAdmin === 'items' ? 'active' : '' ?>"><?= icon("rice", 18) ?> Menu items</a>
    <a href="<?= e(url('/admin/categories.php')) ?>"
       class="<?= $activeAdmin === 'categories' ? 'active' : '' ?>"><?= icon("tag", 18) ?> Categories</a>
    <a href="<?= e(url('/admin/staff.php')) ?>"
       class="<?= $activeAdmin === 'staff' ? 'active' : '' ?>"><?= icon("users", 18) ?> Staff accounts</a>
    <a href="<?= e(url('/index.php')) ?>"><?= icon("eye", 18) ?> View storefront</a>
</div>
