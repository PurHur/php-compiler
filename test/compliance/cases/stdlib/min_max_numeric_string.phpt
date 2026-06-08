--TEST--
stdlib min()/max() — numeric-string variadic coercion (#4347, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

echo max(1, '2', 3.5), "\n";
echo min('3', 2), "\n";
echo max('2', 1), "\n";
echo min('3', 2), "\n";
echo max(['2', 1]), "\n";
echo min(['3', 2]), "\n";
try {
    max('abc', 1);
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
3.5
2
2
2
2
2
TypeError
