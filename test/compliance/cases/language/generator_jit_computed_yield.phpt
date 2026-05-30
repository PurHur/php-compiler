--TEST--
Generator MCJIT resume prefix before yield (issue #3074)
--FILE--
<?php
function gen(): Generator {
    $x = 5;
    yield $x;
    $x = 10;
    yield $x;
}
foreach (gen() as $v) {
    echo $v;
}
--EXPECT--
510
