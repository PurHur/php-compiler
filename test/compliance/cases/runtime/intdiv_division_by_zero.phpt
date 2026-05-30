--TEST--
intdiv() division by zero is catchable in void and used context (#3648)
--FILE--
<?php
echo "void:\n";
try {
    intdiv(1, 0);
    echo "  no throw\n";
} catch (DivisionByZeroError $e) {
    echo "  caught\n";
}

echo "used:\n";
try {
    $x = intdiv(1, 0);
    echo "  no throw\n";
} catch (DivisionByZeroError $e) {
    echo "  caught\n";
}
?>
--EXPECT--
void:
  caught
used:
  caught
