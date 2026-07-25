<?php
// Issue #22925 — array RHS to string offset must E_WARNING Array to string conversion
// then first-byte warning (Zend/zend_execute.c zend_assign_to_string_offset).
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});

$s = 'ab';
$s[0] = [];
var_export($s);
echo "\n";
