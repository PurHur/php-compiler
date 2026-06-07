--TEST--
Language: ++/-- on plain object throws TypeError (zend_operators.c, #6397)
--FILE--
<?php
$o = new stdClass();
try {
    ++$o;
} catch (TypeError $e) {
    echo 'pre-inc:', $e->getMessage(), "\n";
}
try {
    $o++;
} catch (TypeError $e) {
    echo 'post-inc:', $e->getMessage(), "\n";
}
try {
    --$o;
} catch (TypeError $e) {
    echo 'pre-dec:', $e->getMessage(), "\n";
}
try {
    $o--;
} catch (TypeError $e) {
    echo 'post-dec:', $e->getMessage(), "\n";
}
?>
--EXPECT--
pre-inc:Cannot increment stdClass
post-inc:Cannot increment stdClass
pre-dec:Cannot decrement stdClass
post-dec:Cannot decrement stdClass
