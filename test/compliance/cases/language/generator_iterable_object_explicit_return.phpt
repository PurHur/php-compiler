--TEST--
Language: generator iterable/object getReturn() ignores wrapper type (#26468, Zend/zend_generators.c)
--FILE--
<?php
function gi(): iterable {
    yield 1;
    return 42;
}
function go(): object {
    yield 1;
    return 99;
}
$a = gi();
foreach ($a as $v) {
    echo "yi:$v\n";
}
echo 'ri:';
var_export($a->getReturn());
echo "\n";
$b = go();
foreach ($b as $v) {
    echo "yo:$v\n";
}
echo 'ro:';
var_export($b->getReturn());
echo "\n";
--EXPECT--
yi:1
ri:42
yo:1
ro:99
