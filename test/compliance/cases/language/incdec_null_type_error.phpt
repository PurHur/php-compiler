--TEST--
Language: ++/-- on null throws TypeError (zend_operators.c, #4362)
--FILE--
<?php
$x = null;
try {
    ++$x;
    echo "pre-inc:unexpected\n";
} catch (TypeError $e) {
    echo 'pre-inc:', $e->getMessage(), "\n";
}
$x = null;
try {
    $x++;
    echo "post-inc:unexpected\n";
} catch (TypeError $e) {
    echo 'post-inc:', $e->getMessage(), "\n";
}
$x = null;
try {
    --$x;
    echo "pre-dec:unexpected\n";
} catch (TypeError $e) {
    echo 'pre-dec:', $e->getMessage(), "\n";
}
$x = null;
try {
    $x--;
    echo "post-dec:unexpected\n";
} catch (TypeError $e) {
    echo 'post-dec:', $e->getMessage(), "\n";
}
?>
--EXPECT--
pre-inc:Cannot increment null
post-inc:Cannot increment null
pre-dec:Cannot decrement null
post-dec:Cannot decrement null
