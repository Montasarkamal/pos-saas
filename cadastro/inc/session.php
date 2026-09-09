<?php
// inc/session.php

/**
 * بنتحقق الأول: لو السيشين لسه مبدأتش (PHP_SESSION_NONE)
 * بنظبط الإعدادات وبعدين نشغلها.
 */
if (session_status() === PHP_SESSION_NONE) {
    
    $lifetime = 60 * 60 * 2; // جلستك ساعتين

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

} else {
    /**
     * لو السيشين شغالة فعلاً (PHP_SESSION_ACTIVE) 
     * مش هنقدر نغير الـ cookie_params خلاص، فمش هنعمل حاجة عشان نتجنب الـ Warning.
     */
}