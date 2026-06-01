--TEST--
DNF return rejects incompatible values with catchable TypeError (#3094)
--FILE--
<?php
interface A {}
interface B {}
function f(): (A&B)|null {
    return [];
}
try {
    f();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: Return value must be of type (A&B)|null, array given
