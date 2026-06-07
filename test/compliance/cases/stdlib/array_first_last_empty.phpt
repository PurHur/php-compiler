--TEST--
stdlib array_first() / array_last() — empty and all-unset return null (#7293)
--FILE--
<?php
var_dump(array_first([]));
var_dump(array_last([]));
$allUnset = [0 => 1];
unset($allUnset[0]);
var_dump(array_first($allUnset));
var_dump(array_last($allUnset));
--EXPECT--
NULL
NULL
NULL
NULL
