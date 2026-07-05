--TEST--
stdlib array_first() / array_last() — empty array returns null (#7293, #16626, PHP 8.4)
--FILE--
<?php
foreach (['array_first', 'array_last'] as $fn) {
    $v = $fn([]);
    echo $fn, ': ', var_export($v, true), "\n";
}
--EXPECT--
array_first: NULL
array_last: NULL
