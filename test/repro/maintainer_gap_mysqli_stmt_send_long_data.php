<?php
/**
 * Repro #22182 — mysqli_stmt_send_long_data / mysqli_stmt::send_long_data missing.
 * php-src: ext/mysqli/mysqli.stub.php + mysqli_api.c
 */
declare(strict_types=1);

echo 'mysqli_stmt_send_long_data=', function_exists('mysqli_stmt_send_long_data') ? 'yes' : 'NO', "\n";
echo 'method=', method_exists('mysqli_stmt', 'send_long_data') ? 'yes' : 'NO', "\n";

try {
    mysqli_stmt_send_long_data();
    echo "arity=no\n";
} catch (ArgumentCountError $e) {
    echo "arity=yes\n";
}
try {
    mysqli_stmt_send_long_data(false, 0, 'x');
    echo "type_stmt=no\n";
} catch (TypeError $e) {
    echo "type_stmt=yes\n";
}
