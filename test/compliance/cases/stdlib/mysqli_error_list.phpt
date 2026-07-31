--TEST--
mysqli_error_list / mysqli_stmt_error_list (#22225, ext/mysqli/mysqli_nonapi.c)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
foreach (['mysqli_error_list', 'mysqli_stmt_error_list'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
$m = mysqli_init();
$list = mysqli_error_list($m);
echo 'empty=', is_array($list) && $list === [] ? '1' : '0', "\n";
?>
--EXPECT--
mysqli_error_list=1
mysqli_stmt_error_list=1
empty=1
