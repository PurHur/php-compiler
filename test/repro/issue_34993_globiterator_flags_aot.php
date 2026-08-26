<?php

/**
 * Repro #34993 — GlobIterator getFlags/setFlags AOT + excess argc.
 * php-src: ext/spl/spl_directory.c (inherits FilesystemIterator flags).
 */
$g = new GlobIterator('/tmp/*');
try {
    $r = $g->getFlags(1);
    echo 'get_excess:', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'get_excess:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $g->setFlags(0, 1);
    echo "set_excess:ok\n";
} catch (Throwable $e) {
    echo 'set_excess:', get_class($e), ':', $e->getMessage(), "\n";
}
$flags = $g->getFlags();
$g->setFlags(4096);
$after = $g->getFlags();
echo 'ok=', (0 === $flags && 4096 === $after) ? '1' : '0', " flags=$flags after=$after\n";
