--TEST--
stdlib usort/uasort/uksort reject SortDirection named param — Zend arity 2 (#26142, re-#17429)
--FILE--
<?php
declare(strict_types=1);

$a = [3, 1, 2];
try {
    usort(array: $a, callback: static fn ($x, $y) => $x <=> $y, direction: 1);
    echo "named_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    usort($a, static fn ($x, $y) => $x <=> $y, 1);
    echo "positional_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
Unknown named parameter $direction
ArgumentCountError: usort() expects exactly 2 arguments, 3 given
