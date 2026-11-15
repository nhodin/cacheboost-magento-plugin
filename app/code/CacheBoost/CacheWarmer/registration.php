<?php

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

// Loaded through the root composer.json "autoload.files" entry. The guard keeps the
// autoloader usable outside Magento (e.g. the unit test suite, which has no Magento core).
if (class_exists(ComponentRegistrar::class)) {
    ComponentRegistrar::register(
        ComponentRegistrar::MODULE,
        'CacheBoost_CacheWarmer',
        __DIR__
    );
}
