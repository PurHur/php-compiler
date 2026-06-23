--TEST--
Intersection parameter call rejects incompatible object at call site (#10899)
--FILE--
<?php
declare(strict_types=1);

function ic(Countable&Traversable $x): int
{
    return count($x);
}

try {
    ic(new stdClass());
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
?>
--EXPECTF--
TypeError
ic(): Argument #1 ($x) must be of type Countable&Traversable, stdClass given, called in %s on line %d
