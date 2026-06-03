--TEST--
Generator yield from string MCJIT lint (issue #4909)
--FILE--
<?php
function gen(): Generator {
    yield from 'ab';
}
try {
    foreach (gen() as $_) {
    }
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Can use "yield from" only with arrays and Traversables
