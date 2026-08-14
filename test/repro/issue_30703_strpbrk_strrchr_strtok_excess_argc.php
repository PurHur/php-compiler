<?php

declare(strict_types=1);

/**
 * Repro: strpbrk/strrchr/strtok excess argc → ArgumentCountError (#30703).
 *
 * php-src: ext/standard/string.c
 *
 * VM:  php bin/vm.php test/repro/issue_30703_strpbrk_strrchr_strtok_excess_argc.php
 * JIT: php bin/jit.php test/repro/issue_30703_strpbrk_strrchr_strtok_excess_argc.php
 * AOT: php bin/compile.php -o /tmp/str30703 test/repro/issue_30703_strpbrk_strrchr_strtok_excess_argc.php && /tmp/str30703
 */
foreach (['strpbrk', 'strrchr'] as $fn) {
    try {
        $fn('abc', 'b', 'x');
        echo "$fn:NO_THROW\n";
    } catch (Throwable $e) {
        echo $fn, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    strtok('a b', ' ', 'x');
    echo "strtok:NO_THROW\n";
} catch (Throwable $e) {
    echo 'strtok:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'ok:', var_export(strpbrk('abc', 'b'), true), "\n";
echo 'ok:', var_export(strrchr('abc', 'b'), true), "\n";
echo 'ok:', var_export(strtok('a b', ' '), true), "\n";
