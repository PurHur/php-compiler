--TEST--
stdlib fputcsv() — bad stream handle TypeError (#4530, ext/standard/file.c)
--FILE--
<?php
try {
    fputcsv(123, ['a']);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: fputcsv(): Argument #1 ($stream) must be of type resource, int given
