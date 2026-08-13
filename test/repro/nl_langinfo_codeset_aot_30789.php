<?php

declare(strict_types=1);

/**
 * Issue #30789 — AOT idle nl_langinfo(CODESET) must match Zend (UTF-8 via C.UTF-8).
 */
if (!\function_exists('nl_langinfo') || !\defined('CODESET')) {
    \fwrite(\STDERR, "MISSING: nl_langinfo/CODESET\n");
    exit(1);
}

$codeset = \nl_langinfo(\CODESET);
if ('UTF-8' !== $codeset) {
    \fwrite(\STDERR, "fail: CODESET expected UTF-8, got {$codeset}\n");
    exit(1);
}

echo "ok: {$codeset}\n";
