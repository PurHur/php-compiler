<?php

/**
 * AOT-only repro: direct calls (no variable-function) — #30703.
 *
 * php-src: ext/standard/string.c
 *
 * AOT: php bin/compile.php -o /tmp/str30703aot test/repro/issue_30703_strpbrk_strrchr_strtok_excess_argc_aot.php && /tmp/str30703aot
 */
try {
    strpbrk('abc', 'b', 'x');
    echo "strpbrk:NO_THROW\n";
} catch (Throwable $e) {
    echo 'strpbrk:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    strrchr('abc', 'b', 'x');
    echo "strrchr:NO_THROW\n";
} catch (Throwable $e) {
    echo 'strrchr:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    strtok('a b', ' ', 'x');
    echo "strtok:NO_THROW\n";
} catch (Throwable $e) {
    echo 'strtok:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'ok:', var_export(strpbrk('abc', 'b'), true), "\n";
