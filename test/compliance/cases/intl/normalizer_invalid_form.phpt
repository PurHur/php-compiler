--TEST--
normalizer_normalize() invalid form — ValueError (#5153)
--SKIPIF--
<?php
if (!function_exists('normalizer_normalize')) {
    die('skip normalizer not advertised');
}
?>
--FILE--
<?php
declare(strict_types=1);

try {
    normalizer_normalize('x', 99);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
ValueError
