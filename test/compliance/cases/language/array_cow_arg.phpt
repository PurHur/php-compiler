--TEST--
Runtime: array copy-on-write on by-value function args (Zend zend_execute.c parity, #3760)
--FILE--
<?php
function bump(array $x): void {
    $x[0] = 77;
}

function grow(array $x): void {
    $x[] = 2;
}

$c = [1];
bump($c);
echo "bump:", $c[0], "\n";

$d = [1];
grow($d);
echo "grow:", count($d), "\n";
?>
--EXPECT--
bump:1
grow:1
