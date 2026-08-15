--TEST--
AOT: tempnam(null $prefix) TypeError under strict_types (#31246, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);
try {
    tempnam(sys_get_temp_dir(), null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
tempnam(): Argument #2 ($prefix) must be of type string, null given
