--TEST--
stdlib array_merge(null) TypeError catchable (#27478, ext/standard/array.c)
--FILE--
<?php
try {
    array_merge(null, [1]);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$a = null;
try {
    array_merge($a, [1]);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    array_merge([1], null);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "done\n";
--EXPECT--
TypeError:array_merge(): Argument #1 must be of type array, null given
TypeError:array_merge(): Argument #1 must be of type array, null given
TypeError:array_merge(): Argument #2 must be of type array, null given
done
