<?php
// inc/autoload.php — autoloader خفيف لمساحة الاسم Kamaltur\
//
// التطبيق لازم يشتغل على استضافة مشتركة (Hostinger) من غير ما نحتاج
// `composer install` على السيرفر، فبنحل Kamaltur\* مباشرة من src/.
// لو Composer متوفر (dev/CI) بنحمّل autoloader بتاعه كمان — كل حاجة تشتغل
// بنفس الطريقة في الحالتين.
//
// بيتم استدعاؤه من inc/db.php (اللي كل صفحة وكل أداة CLI بتحمّله أصلًا).

if (!function_exists('kamaltur_autoload_register')) {
  function kamaltur_autoload_register(): void
  {
    // لو Composer موجود (vendor/autoload.php) نستخدمه — بيجيله الأولوية عشان
    // هو أسرع وبيشتغل مع tests/ بأي bundle مستقبلي.
    $composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($composerAutoload)) {
      require_once $composerAutoload;
    }

    // Fallback: حل Kamaltur\Foo من src/Foo.php من غير Composer
    // (بيشتغل على الاستضافة من غير تثبيت أي حاجة).
    spl_autoload_register(static function (string $class): void {
      $prefix = 'Kamaltur\\';
      if (!str_starts_with($class, $prefix)) {
        return;
      }
      $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
      $file = dirname(__DIR__) . '/src/' . $rel . '.php';
      if (is_file($file)) {
        require $file;
      }
    });
  }
}

kamaltur_autoload_register();