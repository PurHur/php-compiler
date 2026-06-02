--TEST--
stdlib array_flip() — object array keys throw TypeError (#4268)
--FILE--
<?php
$o = new stdClass();
try {
    array_flip([$o => 1]);
    echo "no exception\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
Illegal offset type
