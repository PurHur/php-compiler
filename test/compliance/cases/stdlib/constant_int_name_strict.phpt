--TEST--
stdlib constant() — strict_types int name TypeError (#11103, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
try {
    constant(1);
} catch (TypeError $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
TypeError: constant(): Argument #1 ($name) must be of type string, int given
