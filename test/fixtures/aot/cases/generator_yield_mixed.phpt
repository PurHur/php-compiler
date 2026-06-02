--TEST--
AOT: generator linear yield + yield from array (issue #2483, Zend/zend_generators.c)
--FILE--
<?php
function gen() {
    yield 1;
    yield from [2, 3];
    yield 4;
}
foreach (gen() as $v) {
    echo $v;
}
echo "\n";
--EXPECT--
1234
