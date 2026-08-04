--TEST--
stdlib array_pop(null) TypeError catchable (#27482, ext/standard/array.c)
--FILE--
<?php
$a = null;
try {
    var_export(array_pop($a));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$b = [1, 2, 3];
try {
    echo array_pop($b), ',', implode(',', $b), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "done\n";
--EXPECT--
TypeError:array_pop(): Argument #1 ($array) must be of type array, null given
3,1,2
done
