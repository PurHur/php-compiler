--TEST--
AOT: extract(null) throws TypeError (#27520, ext/standard/array.c)
--FILE--
<?php
$a = null;
try {
    extract($a);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:extract(): Argument #1 ($array) must be of type array, null given
