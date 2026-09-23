<?php
/**
 * KAMALTUR POS — UI helpers (Phase 2)
 *
 * Bridges the Vite build output in assets/dist/ with the
 * server-rendered pages. Never hardcode hashed filenames.
 */

declare(strict_types=1);

if (!function_exists('vite_asset')) {
    function vite_asset(string $key): string
    {
        static $manifest = null;
        if ($manifest === null) {
            $path = dirname(__DIR__) . '/assets/dist/.vite/manifest.json';
            $raw  = is_file($path) ? file_get_contents($path) : false;
            $manifest = $raw ? (json_decode($raw, true) ?: []) : [];
        }
        $file = isset($manifest[$key]['file'])
            ? (string)$manifest[$key]['file']
            : (isset($manifest[$key]) && is_string($manifest[$key]) ? (string)$manifest[$key] : '');
        return $file !== '' ? '/assets/dist/' . $file : '';
    }
}

if (!function_exists('vite_head')) {
    /** Renders <link rel=stylesheet> + <script> tags for the app bundle. */
    function vite_head(): string
    {
        $css  = vite_asset('style.css');
        $js   = vite_asset('src/main.js');
        $out  = '';
        if ($css !== '') {
            $out .= '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        if ($js !== '') {
            $out .= '<script type="module" src="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
        }
        return $out;
    }
}