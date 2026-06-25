<?php

declare(strict_types=1);

/**
 * Maintainer repro: realpath_cache_get() parent path entries (#11347, ext/standard/url.c).
 */

clearstatcache(true);
$resolved = realpath(__DIR__);
if (false === $resolved) {
    fwrite(STDERR, "realpath failed\n");
    exit(1);
}

$cache = realpath_cache_get();
echo 'count=', count($cache), "\n";

$keys = array_keys($cache);
sort($keys);
foreach ($keys as $key) {
    $entry = $cache[$key];
    echo $key, ' is_dir=';
    var_export($entry['is_dir']);
    echo ' realpath=', $entry['realpath'], "\n";
}
