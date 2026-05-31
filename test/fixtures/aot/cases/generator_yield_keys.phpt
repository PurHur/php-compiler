--TEST--
AOT: generator keyed yield and foreach (issue #3115, Zend/zend_generators.c)
--FILE--
<?php
function gen() {
    yield 'a' => 1;
    yield 'b' => 2;
}
foreach (gen() as $k => $v) {
    echo $k, $v;
}
--EXPECT--
a1b2
