--TEST--
Generator linear yield + yield from array via MCJIT (issue #3074)
--FILE--
<?php
function gen(): Generator {
    yield 1;
    yield from [2, 3];
    yield 4;
}
foreach (gen() as $v) {
    echo $v;
}
--EXPECT--
1234
