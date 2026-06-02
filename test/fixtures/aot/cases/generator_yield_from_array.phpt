--TEST--
AOT: generator yield from array (issue #3115, Zend/zend_generators.c)
--FILE--
<?php
function gen() {
    yield from [1, 2, 3];
}
foreach (gen() as $v) {
    echo $v;
}
echo "\n";
--EXPECT--
123
