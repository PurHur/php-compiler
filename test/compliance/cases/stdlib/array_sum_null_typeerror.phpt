--TEST--
stdlib array_sum(null) TypeError catchable (#27479, ext/standard/array.c)
--FILE--
<?php
try {
    echo array_sum(null), " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$x = null;
try {
    echo array_sum($x), " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "done\n";
--EXPECT--
TypeError:array_sum(): Argument #1 ($array) must be of type array, null given
TypeError:array_sum(): Argument #1 ($array) must be of type array, null given
done
