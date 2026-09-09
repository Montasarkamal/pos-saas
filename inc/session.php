<?php
// inc/session.php

/**
 * بنتحقق الأول: لو السيشين لسه مبدأتش (PHP_SESSION_NONE)
 * بنظبط الإعدادات وبعدين نشغلها.
 */
if (session_status() === PHP_SESSION_NONE) {
    $lifetime = 60 * 60 * 2; // جلستك ساعتين

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    // إعدادات الكوكي (لازم قبل session_start)
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // بيفحص أوتوماتيك لو الموقع شغال HTTPS يخليها Secure
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    ]);

    // ابدأ الجلسة الآن
    session_start();

    $now = time();
    $lastActivity = (int)($_SESSION['_last_activity'] ?? 0);
    if ($lastActivity > 0 && ($now - $lastActivity) > $lifetime) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['_last_activity'] = $now;

    if (empty($_SESSION['_session_started_at'])) {
        session_regenerate_id(true);
        $_SESSION['_session_started_at'] = $now;
    }

} else {
    /**
     * لو السيشين شغالة فعلاً (PHP_SESSION_ACTIVE) 
     * مش هنقدر نغير الـ cookie_params خلاص، فمش هنعمل حاجة عشان نتجنب الـ Warning.
     */
}
