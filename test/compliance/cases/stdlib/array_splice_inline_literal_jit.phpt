--TEST--
stdlib: array_splice() inline empty array literal by-ref Error JIT (#9364, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

try {
    var_export(array_splice([], 0, 0, ['x']));
    echo "inline: no throw\n";
} catch (Throwable $e) {
    echo 'inline: ', get_class($e), ': ', $e->getMessage(), "\n";
}

$a = [];
var_export(array_splice($a, 0, 0, ['x']));
echo "\n";
echo 'var count: ', count($a), "\n";
--EXPECT--
inline: Error: array_splice(): Argument #1 ($array) could not be passed by reference
array (
)
var count: 1
--CREDITS--
PurHur/php-compiler issue #9364
