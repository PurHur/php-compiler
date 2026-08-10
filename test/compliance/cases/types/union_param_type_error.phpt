--TEST--
Union parameter rejects incompatible values with catchable TypeError (#4229)
--FILE--
<?php
declare(strict_types=1);
function f(int|string $x): void {}
try {
    f([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECTF--
TypeError: f(): Argument #1 ($x) must be of type string|int, array given, called in %s on line %d
