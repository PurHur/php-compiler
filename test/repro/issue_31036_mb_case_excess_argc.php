<?php
/**
 * mb_strtolower / mb_strtoupper excess argc → at most (#31036).
 * php-src: ext/mbstring/mbstring.c
 */
foreach (['mb_strtolower', 'mb_strtoupper'] as $f) {
    try {
        $f('ab', 'UTF-8', 'x');
        echo "$f: SILENT\n";
    } catch (Throwable $e) {
        echo "$f: ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
