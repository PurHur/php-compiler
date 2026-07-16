--TEST--
strict_types union param still TypeErrors (no weak coerce) (#19525)
--FILE--
<?php
declare(strict_types=1);
function g(int|string $x): void {
    echo $x;
}
try {
    g(1.5);
    echo "bad\n";
} catch (TypeError $e) {
    echo "strict-ok\n";
}
?>
--EXPECT--
strict-ok
