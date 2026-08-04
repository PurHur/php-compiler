--TEST--
stdlib array_splice(null) TypeError catchable (#27491, ext/standard/array.c)
--FILE--
<?php
$a = null;
try {
    array_splice($a, 0);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$b = [1, 2, 3];
try {
    $removed = array_splice($b, 1, 1);
    echo implode(',', $removed), '|', implode(',', $b), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "done\n";
--EXPECT--
TypeError:array_splice(): Argument #1 ($array) must be of type array, null given
2|1,3
done
