<?php

declare(strict_types=1);

/**
 * Repro: strnatcmp/strnatcasecmp/strcmp excess argc → ArgumentCountError (#30702).
 *
 * php-src: ext/standard/string.c
 *
 * VM:  php bin/vm.php test/repro/issue_30702_strnatcmp_strcmp_excess_argc.php
 * JIT: php bin/jit.php test/repro/issue_30702_strnatcmp_strcmp_excess_argc.php
 * AOT: php bin/compile.php -o /tmp/str30702 test/repro/issue_30702_strnatcmp_strcmp_excess_argc_aot.php && /tmp/str30702
 */
foreach (['strnatcmp', 'strnatcasecmp', 'strcmp'] as $fn) {
    try {
        $fn('a', 'b', 'c');
        echo "$fn:NO_THROW\n";
    } catch (Throwable $e) {
        echo $fn, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
echo 'ok:', (string) strnatcmp('a', 'b'), "\n";
echo 'ok:', (string) strnatcasecmp('A', 'b'), "\n";
echo 'ok:', (string) strcmp('a', 'b'), "\n";
