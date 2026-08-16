--TEST--
JIT: iterator_to_array(null $preserve_keys) TypeError under strict_types (#31340, ext/spl/iterator.c)
--FILE--
<?php
declare(strict_types=1);
try {
    iterator_to_array(new ArrayIterator([1, 2]), null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
iterator_to_array(): Argument #2 ($preserve_keys) must be of type bool, null given
