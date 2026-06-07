--TEST--
Internal builtin exceptions honor user try/catch in void and used context (#4866)
--FILE--
<?php
echo "value void:\n";
try {
    substr_compare('a', 'b', 0, -1);
    echo "  no throw\n";
} catch (ValueError $e) {
    echo "  caught ValueError\n";
}

echo "type void:\n";
try {
    floor(new stdClass());
    echo "  no throw\n";
} catch (TypeError $e) {
    echo "  caught TypeError\n";
}

echo "value used:\n";
try {
    $x = substr_compare('a', 'b', 0, -1);
    echo "  no throw\n";
} catch (ValueError $e) {
    echo "  caught ValueError\n";
}
?>
--EXPECT--
value void:
  caught ValueError
type void:
  caught TypeError
value used:
  caught ValueError
