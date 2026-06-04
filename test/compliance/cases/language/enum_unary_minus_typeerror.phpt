--TEST--
Unary minus on backed enum case throws TypeError (issue #5804, zend_operators.c)
--FILE--
<?php
declare(strict_types=1);
enum E: int { case A = 1; }
try {
    var_export(-E::A);
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError:Unsupported operand types: E * int
