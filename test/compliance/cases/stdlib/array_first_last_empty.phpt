--TEST--
stdlib array_first() / array_last() — empty array Error (#11832, PHP 8.4)
--FILE--
<?php
foreach (['array_first', 'array_last'] as $fn) {
    try {
        $fn([]);
        echo $fn, ": uncaught\n";
    } catch (Error $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
array_first: array_first(): Argument #1 ($array) must not be empty
array_last: array_last(): Argument #1 ($array) must not be empty
