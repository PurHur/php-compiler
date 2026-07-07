--TEST--
stdlib array_find()/array_find_key() null callback — TypeError (#17133, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['array_find', 'array_find_key'] as $fn) {
    try {
        $fn([1], null);
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), "\n";
    }
}
?>
--EXPECT--
array_find: TypeError
array_find_key: TypeError
