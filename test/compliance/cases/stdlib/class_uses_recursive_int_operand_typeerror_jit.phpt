--TEST--
Stdlib: class_uses_recursive() int operand — TypeError (JIT, forward profile, #16773)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4') {
    echo 'skip forward profile only';
}
?>
--FILE--
<?php
try {
    class_uses_recursive(123);
    echo "fail\n";
} catch (TypeError $e) {
    echo "ok\n";
}
--EXPECT--
ok
