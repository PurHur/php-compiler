--TEST--
AOT: scandir(null $sorting_order) TypeError under strict_types (#31244, ext/standard/dir.c)
--FILE--
<?php
declare(strict_types=1);
try {
    scandir('.', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
scandir(): Argument #2 ($sorting_order) must be of type int, null given
