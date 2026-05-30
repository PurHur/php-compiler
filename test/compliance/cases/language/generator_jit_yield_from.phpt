--TEST--
Generator yield from array via MCJIT (issue #3074)
--FILE--
<?php
function gen(): Generator {
    yield from [1, 2, 3];
}
foreach (gen() as $v) {
    echo $v;
}
--EXPECT--
123
