--TEST--
named argument by valid parameter name (VM)
--FILE--
<?php
function g(int $a): int {
    return $a;
}
echo g(a: 5);
--EXPECT--
5
