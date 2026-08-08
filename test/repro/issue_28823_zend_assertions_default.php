<?php

/**
 * Issue #28823 — zend.assertions compiled default is 1 (php -n / Zend/zend_ini.c).
 */
declare(strict_types=1);

error_reporting(E_ALL);

echo 'assertions=', ini_get('zend.assertions'), ' exception=', ini_get('assert.exception'), "\n";
try {
    assert(false, 'msg');
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo 'THROW ', get_class($e), "\n";
}
