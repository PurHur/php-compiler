--TEST--
AOT: sort() on null throws Error could not be passed by reference (#4333/#29624, ext/standard/array.c)
--FILE--
<?php
try {
    sort(null);
    echo "uncaught\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
sort(): Argument #1 ($array) could not be passed by reference
