<?php

declare(strict_types=1);

/**
 * Repro: password_get_info() excess argc → ArgumentCountError (#30712).
 *
 * php-src: ext/standard/password.c
 *
 * VM:  php bin/vm.php test/repro/issue_30712_password_get_info_excess_argc.php
 * AOT: php bin/compile.php -o /tmp/pgi30712 test/repro/issue_30712_password_get_info_excess_argc.php && /tmp/pgi30712
 */
try {
    password_get_info('x', 1);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
