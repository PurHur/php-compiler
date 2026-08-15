<?php

/**
 * AOT-only repro: direct calls (no variable-function) — #30702.
 *
 * php-src: ext/standard/string.c
 *
 * AOT: php bin/compile.php -o /tmp/str30702aot test/repro/issue_30702_strnatcmp_strcmp_excess_argc_aot.php && /tmp/str30702aot
 */
try {
    strnatcmp('a', 'b', 'c');
    echo "strnatcmp:NO_THROW\n";
} catch (Throwable $e) {
    echo 'strnatcmp:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    strnatcasecmp('a', 'b', 'c');
    echo "strnatcasecmp:NO_THROW\n";
} catch (Throwable $e) {
    echo 'strnatcasecmp:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    strcmp('a', 'b', 'c');
    echo "strcmp:NO_THROW\n";
} catch (Throwable $e) {
    echo 'strcmp:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'ok:', (string) strcmp('a', 'b'), "\n";
