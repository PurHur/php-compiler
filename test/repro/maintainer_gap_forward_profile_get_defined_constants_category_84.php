<?php

declare(strict_types=1);

// Issue #17436 — get_defined_constants(category:) on PHP_COMPILER_PROFILE=8.4
$core = get_defined_constants(category: 'Core');
echo array_key_exists('PHP_VERSION', $core) ? "ok\n" : "missing PHP_VERSION\n";
