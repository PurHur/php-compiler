<?php
/**
 * Issue #31195 — guest zend.assertions must match host process defaults (Docker php.ini -1).
 *
 * Without guest -d: ini_get and assert(false) track the host PHP process.
 * With -d zend.assertions=1: still throws AssertionError.
 */
declare(strict_types=1);

error_reporting(E_ALL);
echo 'zend.assertions=', var_export(ini_get('zend.assertions'), true), "\n";
try {
    assert(false, 'nope');
    echo "SURVIVED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
