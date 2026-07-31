--TEST--
mysqli_set_opt is alias of mysqli_options (#22227, ext/mysqli/mysqli.stub.php)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
foreach (['mysqli_options', 'mysqli_set_opt'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}
?>
--EXPECT--
mysqli_options=Y
mysqli_set_opt=Y
