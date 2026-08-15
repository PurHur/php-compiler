--TEST--
stdlib linkinfo(null) TypeError under strict_types (#31262, ext/standard/link.c)
--FILE--
<?php
declare(strict_types=1);
try {
    linkinfo(null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
linkinfo(): Argument #1 ($path) must be of type string, null given
