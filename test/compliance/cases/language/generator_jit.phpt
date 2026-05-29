--TEST--
Generator foreach via MCJIT (issue #3074)
--FILE--
<?php
function gen(): Generator {
    yield 1;
    yield 2;
}
foreach (gen() as $v) {
    echo $v;
}
--EXPECT--
12
