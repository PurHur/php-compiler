--TEST--
stdlib set_include_path(null) TypeError under strict_types (#30359, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
try {
    var_export(set_include_path(null));
    echo "\nfail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:set_include_path(): Argument #1 ($include_path) must be of type string, null given
