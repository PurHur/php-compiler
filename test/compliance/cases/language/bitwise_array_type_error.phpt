--TEST--
Language: bitwise &|^ with array operand must TypeError (zend_operators.c, #5294)
--FILE--
<?php
foreach (['&', '|', '^'] as $op) {
    try {
        eval('return [] '.$op.' 1;');
    } catch (TypeError $e) {
        echo 'TypeError:', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
TypeError:Unsupported operand types: array & int
TypeError:Unsupported operand types: array | int
TypeError:Unsupported operand types: array ^ int
