--TEST--
stdlib array_find()/array_find_key() null callback — TypeError JIT (#17133)
--FILE--
<?php
foreach (['array_find', 'array_find_key'] as $fn) {
    try {
        if ('array_find' === $fn) {
            array_find([1], null);
        } else {
            array_find_key(['a' => 1], null);
        }
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', get_class($e), "\n";
    }
}
?>
--JIT--
--EXPECT--
array_find: TypeError
array_find_key: TypeError
