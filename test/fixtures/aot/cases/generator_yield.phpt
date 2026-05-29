--TEST--
AOT: generator yield and foreach (issue #3115, Zend/zend_generators.c)
--SKIPIF--
<?php exit('skip until GeneratorHelper foreach execute segfault is fixed (#3115)'); ?>
--FILE--
<?php
function gen() {
    yield 1;
    yield 2;
}
foreach (gen() as $v) {
    echo $v;
}
--EXPECT--
12
