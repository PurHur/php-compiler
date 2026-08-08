--TEST--
JIT: stdlib implode(array, string) TypeError cites Arg #1 ($separator) on PROFILE=8.4 (#29087)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$arr = ['a', 'b'];
$glue = '-';
try {
    implode($arr, $glue);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
implode(): Argument #1 ($separator) must be of type string, array given
