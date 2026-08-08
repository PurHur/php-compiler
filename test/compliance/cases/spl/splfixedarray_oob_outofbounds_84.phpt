--TEST--
SplFixedArray OOB throws OutOfBoundsException under PROFILE≥8.4 (#28819, ext/spl/spl_fixedarray.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$a = new SplFixedArray(3);
try {
    $a[5] = 1;
    echo "set-no-throw\n";
} catch (Throwable $e) {
    echo 'set:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    echo $a[5];
    echo "get-no-throw\n";
} catch (Throwable $e) {
    echo 'get:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unset($a[5]);
    echo "unset-no-throw\n";
} catch (Throwable $e) {
    echo 'unset:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $a->offsetSet(5, 1);
    echo "method-no-throw\n";
} catch (Throwable $e) {
    echo 'method:', get_class($e), ':', $e->getMessage(), "\n";
}

$a[0] = 9;
echo 'ok:', $a[0], "\n";
?>
--EXPECT--
set:OutOfBoundsException:Index invalid or out of range
get:OutOfBoundsException:Index invalid or out of range
unset:OutOfBoundsException:Index invalid or out of range
method:OutOfBoundsException:Index invalid or out of range
ok:9
