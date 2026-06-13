<?php

declare(strict_types=1);

// Compile-only smoke (#3463): realpath cache introspection builtins lower for AOT.
$path = __DIR__ . '/../../../../composer.json';
realpath($path);
echo realpath_cache_size() > 0 ? "size_ok\n" : "size_fail\n";
$cache = realpath_cache_get();
echo count($cache) >= 0 ? "cache_ok\n" : "cache_fail\n";
