<?php
/**
 * Issue #29551 — host `php -d zend.assertions=-1|0|1 bin/vm.php` must match Zend assert gating.
 *
 * With zend.assertions ≤ 0, assert(false) is a no-op; with 1 it throws AssertionError.
 */
declare(strict_types=1);

error_reporting(E_ALL);
echo 'ini=', ini_get('zend.assertions'), "\n";
try {
    assert(false, 'nope');
    echo "AFTER\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "done\n";
