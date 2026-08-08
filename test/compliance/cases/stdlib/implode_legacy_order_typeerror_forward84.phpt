--TEST--
stdlib implode(array, string) TypeError cites Arg #1 ($separator) on PROFILE=8.4 (#29087)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    implode(['a', 'b'], '-');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    join(['a', 'b'], '-');
    echo "uncaught join\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
implode(): Argument #1 ($separator) must be of type string, array given
join(): Argument #2 ($array) must be of type ?array, string given
