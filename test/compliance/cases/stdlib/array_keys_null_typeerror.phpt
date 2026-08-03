--TEST--
stdlib array_keys(null) TypeError catchable (#27472, ext/standard/array.c)
--FILE--
<?php
try {
    var_export(array_keys(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$x = null;
try {
    var_export(array_keys($x));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "done\n";
--EXPECT--
TypeError:array_keys(): Argument #1 ($array) must be of type array, null given
TypeError:array_keys(): Argument #1 ($array) must be of type array, null given
done
