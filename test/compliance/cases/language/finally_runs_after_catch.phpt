--TEST--
Language: finally executes after caught exception and return-from-catch (#14959)
--FILE--
<?php
$x = 0;
try {
    throw new Exception('e');
} catch (Exception $e) {
    // handled
} finally {
    $x = 1;
}
echo $x, "\n";

function f(): int {
    try {
        throw new Exception('e');
    } catch (Exception $e) {
        return 1;
    } finally {
        echo "F";
    }
}
echo f(), "\n";
--EXPECT--
1
F1

