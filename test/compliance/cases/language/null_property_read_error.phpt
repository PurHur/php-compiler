--TEST--
Language: read property on null throws catchable Error (#7431; zend_execute.c)
--FILE--
<?php
try {
    $x = null;
    $y = $x->prop;
    echo "no throw\n";
} catch (Error $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}

try {
    $x = null;
    echo $x->prop;
    echo "no throw\n";
} catch (Error $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}

try {
    $x = null;
    $x->prop++;
    echo "no throw\n";
} catch (Error $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Attempt to read property "prop" on null
Error: Attempt to read property "prop" on null
Error: Attempt to increment/decrement property "prop" on null
