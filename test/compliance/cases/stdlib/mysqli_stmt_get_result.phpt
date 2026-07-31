--TEST--
ext/mysqli mysqli_stmt_get_result / mysqli_stmt::get_result registration (#22162)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
echo 'mysqli_stmt_get_result:', function_exists('mysqli_stmt_get_result') ? 'yes' : 'no', "\n";
$rc = new ReflectionClass('mysqli_stmt');
echo 'stmt::get_result:', $rc->hasMethod('get_result') ? 'yes' : 'no', "\n";
?>
--EXPECT--
mysqli_stmt_get_result:yes
stmt::get_result:yes
