--TEST--
ext/mysqli mysqli_sql_exception + mysqli_init() + new mysqli() zero-arg bootstrap (#21803, ext/mysqli/mysqli.c)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
declare(strict_types=1);

echo class_exists('mysqli_sql_exception') ? "mysqli_sql_exception=yes\n" : "mysqli_sql_exception=no\n";
echo class_exists('mysqli_driver') ? "mysqli_driver=yes\n" : "mysqli_driver=no\n";
echo function_exists('mysqli_init') ? "mysqli_init=yes\n" : "mysqli_init=no\n";
echo function_exists('mysqli_prepare') ? "mysqli_prepare=yes\n" : "mysqli_prepare=no\n";
echo function_exists('mysqli_stmt_execute') ? "mysqli_stmt_execute=yes\n" : "mysqli_stmt_execute=no\n";
echo class_exists('mysqli_stmt') ? "mysqli_stmt=yes\n" : "mysqli_stmt=no\n";

$init = mysqli_init();
echo $init instanceof mysqli ? "mysqli_init_object=yes\n" : "mysqli_init_object=no\n";

$direct = new mysqli();
echo $direct instanceof mysqli ? "new_mysqli_object=yes\n" : "new_mysqli_object=no\n";
?>
--EXPECT--
mysqli_sql_exception=yes
mysqli_driver=yes
mysqli_init=yes
mysqli_prepare=yes
mysqli_stmt_execute=yes
mysqli_stmt=yes
mysqli_init_object=yes
new_mysqli_object=yes
