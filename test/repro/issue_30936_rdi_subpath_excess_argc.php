<?php

/**
 * Repro #30936 — RecursiveDirectoryIterator getSubPath/getSubPathname excess argc.
 * php-src: ext/spl/spl_directory.c
 */
$it = new RecursiveDirectoryIterator(__DIR__);
$it->rewind();
if (!$it->valid()) {
    echo "empty\n";
    exit(0);
}
foreach ([
    'sub' => static fn () => $it->getSubPath(1),
    'subname' => static fn () => $it->getSubPathname(1),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ':', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
$okS = $it->getSubPath();
$okN = $it->getSubPathname();
echo 'ok=', (is_string($okS) && is_string($okN)) ? '1' : '0', "\n";
