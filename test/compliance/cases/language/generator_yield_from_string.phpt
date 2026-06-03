--TEST--
Generator yield from string throws Error (issue #4909, zend_generators.c)
--FILE--
<?php
function gen() {
    yield from 'ab';
}
try {
    foreach (gen() as $_) {
    }
    echo "no throw\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Can use "yield from" only with arrays and Traversables
