--TEST--
ext/mysqli mysqli_stmt_send_long_data + mysqli_stmt::send_long_data (#22182, php-src mysqli_api.c)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
declare(strict_types=1);

echo function_exists('mysqli_stmt_send_long_data') ? "fn=yes\n" : "fn=no\n";
echo method_exists('mysqli_stmt', 'send_long_data') ? "method=yes\n" : "method=no\n";

try {
    mysqli_stmt_send_long_data();
    echo "arity=no\n";
} catch (ArgumentCountError $e) {
    echo "arity=yes\n";
}
try {
    mysqli_stmt_send_long_data(false, 0, 'blob');
    echo "type_stmt=no\n";
} catch (TypeError $e) {
    echo "type_stmt=yes\n";
}
?>
--EXPECT--
fn=yes
method=yes
arity=yes
type_stmt=yes
