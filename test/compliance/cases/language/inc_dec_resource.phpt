--TEST--
Language: ++/-- on stream resource throws TypeError (zend_operators.c, #6396)
--FILE--
<?php
$r = fopen('php://memory', 'r+');
try {
    $r++;
} catch (TypeError $e) {
    echo 'post-inc:', $e->getMessage(), "\n";
}
try {
    ++$r;
} catch (TypeError $e) {
    echo 'pre-inc:', $e->getMessage(), "\n";
}
try {
    $r--;
} catch (TypeError $e) {
    echo 'post-dec:', $e->getMessage(), "\n";
}
try {
    --$r;
} catch (TypeError $e) {
    echo 'pre-dec:', $e->getMessage(), "\n";
}
$closed = fopen('php://memory', 'r+');
fclose($closed);
try {
    $closed++;
} catch (TypeError $e) {
    echo 'closed:', $e->getMessage(), "\n";
}
?>
--EXPECT--
post-inc:Cannot increment resource
pre-inc:Cannot increment resource
post-dec:Cannot decrement resource
pre-dec:Cannot decrement resource
closed:Cannot increment resource
