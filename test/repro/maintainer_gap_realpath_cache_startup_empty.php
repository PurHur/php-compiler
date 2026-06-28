<?php

declare(strict_types=1);

/**
 * realpath_cache_get() at request start — bootstrap script path entries (#12384).
 */

$cache = realpath_cache_get();
$count = count($cache);
echo 'startup_count='.$count."\n";

if ($count < 1) {
    exit(1);
}

$script = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
$resolved = realpath($script);
if (false === $resolved) {
    exit(1);
}

if (!isset($cache[$script]) && !isset($cache[$resolved])) {
    fwrite(STDERR, "fail: script path missing from cache\n");
    exit(1);
}

echo "ok\n";
