--TEST--
ext/mysqli mysqli_stmt_init / mysqli_stmt_prepare two-step API (#22215, php-src mysqli_api.c)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
declare(strict_types=1);

echo function_exists('mysqli_stmt_init') ? "init=yes\n" : "init=no\n";
echo function_exists('mysqli_stmt_prepare') ? "prepare=yes\n" : "prepare=no\n";
echo method_exists('mysqli', 'stmt_init') ? "mysqli_stmt_init=yes\n" : "mysqli_stmt_init=no\n";
echo method_exists('mysqli_stmt', 'prepare') ? "stmt_prepare=yes\n" : "stmt_prepare=no\n";

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
?>
--EXPECT--
init=yes
prepare=yes
mysqli_stmt_init=yes
stmt_prepare=yes
arity_init=yes
type_prepare=yes
