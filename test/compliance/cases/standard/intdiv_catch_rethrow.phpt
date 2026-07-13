--TEST--
intdiv() DivisionByZeroError survives catch/rethrow (#18579)
--FILE--
<?php
echo "bare:\n";
try {
    intdiv(1, 0);
    echo "  no throw\n";
} catch (Throwable $e) {
    echo '  '.get_class($e).': '.$e->getMessage()."\n";
}

echo "rethrow:\n";
try {
    try {
        intdiv(1, 0);
    } catch (Throwable $e) {
        throw $e;
    }
} catch (Throwable $e) {
    echo '  '.get_class($e).': '.$e->getMessage()."\n";
}

echo "nested rethrow:\n";
try {
    try {
        try {
            intdiv(1, 0);
        } catch (Throwable $e) {
            throw $e;
        }
    } catch (Throwable $e) {
        throw $e;
    }
} catch (Throwable $e) {
    echo '  '.get_class($e).': '.$e->getMessage()."\n";
}
?>
--EXPECT--
bare:
  DivisionByZeroError: Division by zero
rethrow:
  DivisionByZeroError: Division by zero
nested rethrow:
  DivisionByZeroError: Division by zero
