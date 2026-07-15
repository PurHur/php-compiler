--TEST--
intdiv() catch-rethrow delivers DivisionByZeroError not VM fatal (#18579)
--FILE--
<?php
try {
    intdiv(1, 0);
} catch (Throwable $e) {
    echo "bare:", get_class($e), "\n";
}

try {
    try {
        intdiv(1, 0);
    } catch (Throwable $e) {
        throw $e;
    }
} catch (Throwable $e) {
    echo "rethrow:", get_class($e), "\n";
}
--EXPECT--
bare:DivisionByZeroError
rethrow:DivisionByZeroError
