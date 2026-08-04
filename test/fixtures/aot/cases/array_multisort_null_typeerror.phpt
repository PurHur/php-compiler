--TEST--
AOT: array_multisort(null) TypeError catchable (#27511, php-src ext/standard/array.c)
--FILE--
<?php
$a = null;
try {
    var_export(array_multisort($a));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:array_multisort(): Argument #1 ($array) must be an array or a sort flag
