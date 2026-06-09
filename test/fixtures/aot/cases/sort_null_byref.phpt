--TEST--
AOT: sort() on null throws Error cannot be passed by reference (#4333, ext/standard/array.c)
--FILE--
<?php
try {
    sort(null);
    echo "uncaught\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
sort(): Argument #1 ($array) cannot be passed by reference
