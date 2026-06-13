<?php

declare(strict_types=1);

$path = __DIR__.'/../../composer.json';
if (!is_file($path)) {
    echo "skip\n";
    exit(0);
}

realpath($path);
echo realpath_cache_size() > 0 ? "size_ok\n" : "size_fail\n";
$cache = realpath_cache_get();
echo isset($cache[$path]) || count($cache) > 0 ? "cache_ok\n" : "cache_empty\n";
if (isset($cache[$path])) {
    $entry = $cache[$path];
    echo isset($entry['realpath']) && isset($entry['expires']) ? "entry_ok\n" : "entry_bad\n";
} else {
    foreach ($cache as $entry) {
        echo isset($entry['realpath']) && isset($entry['expires']) ? "entry_ok\n" : "entry_bad\n";
        break;
    }
}
