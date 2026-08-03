--TEST--
stdlib in_array(null haystack) TypeError catchable (#27448, ext/standard/array.c)
--FILE--
<?php
try {
    var_export(in_array(1, null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$x = null;
try {
    var_export(in_array(1, $x));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "done\n";
--EXPECT--
TypeError:in_array(): Argument #2 ($haystack) must be of type array, null given
TypeError:in_array(): Argument #2 ($haystack) must be of type array, null given
done
