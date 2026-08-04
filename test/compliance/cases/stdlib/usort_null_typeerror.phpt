--TEST--
stdlib usort(null) TypeError catchable (#27510, ext/standard/array.c)
--FILE--
<?php
$a = null;
try {
    var_export(usort($a, fn($x, $y) => $x <=> $y));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$b = [3, 1, 2];
try {
    var_export(usort($b, fn($x, $y) => $x <=> $y));
    echo '|', implode(',', $b), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "done\n";
--EXPECT--
TypeError:usort(): Argument #1 ($array) must be of type array, null given
true|1,2,3
done
