<?php

declare(strict_types=1);

/**
 * Issue #14725 — vsprintf() missing $args array must throw ArgumentCountError (not LogicException).
 */

try {
    vsprintf('%d');
} catch (Throwable $e) {
    if ($e instanceof ArgumentCountError) {
        echo "ok\n";
        exit(0);
    }
    echo 'fail: ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}
echo "fail: no exception\n";
exit(1);
