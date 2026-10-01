<?php
/**
 * includes/header.php  —  shared page top: <head>, nav bar, flash messages
 *
 * Set $pageTitle before including this file.
 */
require_once __DIR__ . '/functions.php';

$pageTitle = $pageTitle ?? 'Menu';
$user      = current_user();
$cartQty   = cart_count();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="format-detection" content="telephone=no">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/style.css')) ?>">
    <?php /* The whole client-side script: confirm prompts and auto-submit.
             No <script> blocks live in the templates — the page is served
             with script-src 'self', so inline script would be refused. */ ?>
    <script src="<?= e(url('/assets/app.js')) ?>" defer></script>
</head>
<body>

<header class="topbar">
    <div class="wrap topbar-inner">
        <a class="brand" href="<?= e(url('/index.php')) ?>">
            <span class="brand-mark"><?= icon('plate', 22) ?></span>
            <span>
                <strong><?= e(APP_NAME) ?></strong>
                <small>Campus Canteen Ordering</small>
            </span>
        </a>

        <nav class="mainnav">
            <a href="<?= e(url('/index.php')) ?>">Menu</a>

            <?php if (is_logged_in()): ?>
                <a href="<?= e(url('/orders.php')) ?>">My Orders</a>
            <?php endif; ?>

            <?php if (is_admin()): ?>
                <a href="<?= e(url('/admin/index.php')) ?>" class="nav-admin">Admin</a>
            <?php endif; ?>

            <a href="<?= e(url('/cart.php')) ?>" class="nav-cart">
                Cart
                <?php if ($cartQty > 0): ?>
                    <span class="cart-pill"><?= (int) $cartQty ?></span>
                <?php endif; ?>
            </a>

            <?php if (is_logged_in()): ?>
                <a class="nav-user" href="<?= e(url('/account.php')) ?>"
                   title="Your account — <?= e($user['email']) ?>">
                    <?= e($user['name']) ?>
                </a>
                <a href="<?= e(url('/logout.php')) ?>" class="btn btn-ghost btn-sm">Sign out</a>
            <?php else: ?>
                <a href="<?= e(url('/login.php')) ?>" class="btn btn-ghost btn-sm">Sign in</a>
                <a href="<?= e(url('/register.php')) ?>" class="btn btn-primary btn-sm">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="wrap page">

    <?php foreach (take_flashes() as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
