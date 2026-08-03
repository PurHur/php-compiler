--TEST--
stdlib count(null)/sizeof(null) TypeError catchable (#27446, ext/standard/array.c)
--FILE--
<?php
try {
    var_export(count(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(sizeof(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "done\n";
--EXPECT--
TypeError:count(): Argument #1 ($value) must be of type Countable|array, null given
TypeError:sizeof(): Argument #1 ($value) must be of type Countable|array, null given
done
