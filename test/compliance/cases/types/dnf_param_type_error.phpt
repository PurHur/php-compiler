--TEST--
DNF parameter rejects incompatible values with catchable TypeError (#3094)
--FILE--
<?php
interface A {}
interface B {}
function f((A&B)|null $x): void {}
try {
    f([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: Argument must be of type (A&B)|null, array given
