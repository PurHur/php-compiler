--TEST--
AOT: http_response_code(null) under strict_types throws TypeError (#30019)
--FILE--
<?php
declare(strict_types=1);

try {
    var_export(http_response_code(null));
    echo " uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
http_response_code(): Argument #1 ($response_code) must be of type int, null given
