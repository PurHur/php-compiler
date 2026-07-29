<?php
/**
 * #24968 — setcookie/setrawcookie named path: without expires_or_options must not crash.
 */
error_reporting(E_ALL);
ob_start();
try {
    var_export(setcookie(name: 'n', value: 'v', path: '/'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(setrawcookie(name: 'n', value: 'v', path: '/'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
