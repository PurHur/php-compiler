--TEST--
Backed enum in arithmetic throws TypeError (issue #4811, Zend/zend_operators.c)
--FILE--
<?php
declare(strict_types=1);
enum E: string { case A = 'x'; }
try {
    var_export(E::A + 1);
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError:Unsupported operand types: E + int
