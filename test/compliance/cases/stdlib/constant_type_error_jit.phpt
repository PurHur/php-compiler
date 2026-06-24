--TEST--
stdlib constant() — JIT TypeError for non-string name (#4846)
--FILE--
<?php
try {
    constant(1);
} catch (Error $e) {
    echo "Error\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
Error
