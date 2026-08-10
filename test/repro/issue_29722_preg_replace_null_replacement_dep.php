<?php
/**
 * Issue #29722 — preg_replace null $replacement DEP type is array|string (Zend).
 */
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno) {
        echo 'DEP:', $errstr, "\n";

        return true;
    }

    return false;
});
var_export(preg_replace('/a/', null, 'a'));
echo "\n";
