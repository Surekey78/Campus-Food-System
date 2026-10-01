<?php
/**
 * public/logout.php  —  end the session
 *
 * Accepts POST (preferred, CSRF-checked) and also GET so a plain link
 * works as a convenience.
 */
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
session_start();                 // fresh session so we can show a flash

flash('You have been signed out. See you at lunch!', 'info');
redirect('/index.php');
