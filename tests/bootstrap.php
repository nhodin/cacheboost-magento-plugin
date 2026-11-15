<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/Unit/Support/MemoryCache.php';
require_once __DIR__ . '/Unit/Support/MemoryFlags.php';

// Magento's translation helper — returns the string with %1/%2/... replaced.
if (!function_exists('__')) {
    function __($text, ...$args): string
    {
        $str = (string) $text;
        foreach ($args as $i => $arg) {
            $str = str_replace('%' . ($i + 1), (string) $arg, $str);
        }
        return $str;
    }
}
