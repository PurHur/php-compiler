--TEST--
AOT: usort(null) TypeError catchable (#27510, php-src ext/standard/array.c)
--FILE--
<?php
$a = null;
try {
    var_export(usort($a, fn($x, $y) => $x <=> $y));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:usort(): Argument #1 ($array) must be of type array, null given
