--TEST--
DNF parameter (Countable&Traversable)|array AOT accepts array (#27624)
--FILE--
<?php
function f((Countable&Traversable)|array $x): int {
    return is_array($x) ? count($x) : iterator_count($x);
}
echo f([1, 2, 3]), "\n";
?>
--EXPECT--
3
