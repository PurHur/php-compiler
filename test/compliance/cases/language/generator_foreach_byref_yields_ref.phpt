--TEST--
Generator foreach by-reference over function &gen() binds yielded value (Zend/zend_generators.c, #23814)
--FILE--
<?php
function &gen() {
    $value = 1;
    yield $value;
}
foreach (gen() as &$n) {
    $n = 9;
    echo "n=$n\n";
}
--EXPECT--
n=9
