--TEST--
foreach over inline array literal with multiple closures stays invokable (#14120)
--FILE--
<?php
foreach ([fn () => 1 / 0, fn () => 1 % 0] as $i => $op) {
    try {
        $op();
    } catch (DivisionByZeroError $e) {
        echo "$i: DivisionByZeroError\n";
    } catch (Throwable $e) {
        echo "$i: wrong:", get_class($e), "\n";
    }
}
?>
--EXPECT--
0: DivisionByZeroError
1: DivisionByZeroError
