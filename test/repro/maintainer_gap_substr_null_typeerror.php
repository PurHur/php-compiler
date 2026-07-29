<?php
/**
 * Was filed under #24694 assuming Zend 8.4 TypeErrors null→string for substr().
 * Correct Zend 8.4 behavior is deprecate+coerce (#24817 / #21189); TypeError is PHP 9.0.
 * Keep this path as a soft-null guard so the wrong expectation cannot regress quietly.
 */
error_reporting(E_ALL);
set_error_handler(static function (): bool {
    return true;
});
try {
    $result = substr(null, 0);
    echo "OK soft-null: ", var_export($result, true), "\n";
} catch (\TypeError $e) {
    echo "BUG TypeError: ", $e->getMessage(), "\n";
}
