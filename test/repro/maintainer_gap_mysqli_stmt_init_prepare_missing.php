<?php
/**
 * Repro #22215 — mysqli_stmt_init / mysqli_stmt_prepare two-step API missing.
 * php-src: ext/mysqli/mysqli.stub.php + mysqli_api.c
 */
declare(strict_types=1);

foreach ([
    'mysqli_connect',
    'mysqli_prepare',
    'mysqli_stmt_bind_param',
    'mysqli_stmt_execute',
    'mysqli_stmt_init',
    'mysqli_stmt_prepare',
] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}
echo 'mysqli::stmt_init=', method_exists('mysqli', 'stmt_init') ? 'yes' : 'NO', "\n";
echo 'mysqli_stmt::prepare=', method_exists('mysqli_stmt', 'prepare') ? 'yes' : 'NO', "\n";

try {
    mysqli_stmt_init();
    echo "arity_init=no\n";
} catch (ArgumentCountError $e) {
    echo "arity_init=yes\n";
}
try {
    mysqli_stmt_prepare(false, 'SELECT 1');
    echo "type_prepare=no\n";
} catch (TypeError $e) {
    echo "type_prepare=yes\n";
}
