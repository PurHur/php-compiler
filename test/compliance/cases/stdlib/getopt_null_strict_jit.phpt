--TEST--
JIT getopt(null) TypeError under strict_types (#30358, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
try {
    var_export(getopt(null));
    echo "\nfail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:getopt(): Argument #1 ($short_options) must be of type string, null given
