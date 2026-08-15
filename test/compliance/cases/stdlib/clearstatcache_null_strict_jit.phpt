--TEST--
JIT: clearstatcache(null) TypeError under strict_types (#31245, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);
try {
    clearstatcache(null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
clearstatcache(): Argument #1 ($clear_realpath_cache) must be of type bool, null given
