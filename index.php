<?php
require __DIR__ . '/inc/session.php';

if (!empty($_SESSION['uid'])) {
    header('Location: /dashboard.php', true, 302);
} else {
    header('Location: /login.php', true, 302);
}
exit;