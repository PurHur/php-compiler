<?php

/**
 * #30551 — chmod/touch/tempnam excess argc → ArgumentCountError (php-src filestat.c).
 */
error_reporting(E_ALL);

$cases = [
    'chmod("/tmp", 0777, "x")',
    'chmod("/tmp")',
    'touch("/tmp/t", time(), time(), "x")',
    'touch()',
    'tempnam("/tmp", "p", "x")',
    'tempnam("/tmp")',
];
foreach ($cases as $code) {
    try {
        eval($code.';');
        echo "$code => NO_THROW\n";
    } catch (Throwable $e) {
        echo $code, ' => ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
