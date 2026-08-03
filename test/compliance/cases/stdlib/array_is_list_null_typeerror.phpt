--TEST--
stdlib array_is_list(null) TypeError catchable (#27474, ext/standard/array.c)
--FILE--
<?php
try {
    var_export(array_is_list(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$x = null;
try {
    var_export(array_is_list($x));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "done\n";
--EXPECT--
TypeError:array_is_list(): Argument #1 ($array) must be of type array, null given
TypeError:array_is_list(): Argument #1 ($array) must be of type array, null given
done
