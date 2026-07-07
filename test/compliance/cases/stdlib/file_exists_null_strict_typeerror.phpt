--TEST--
stdlib file_exists() — null filename TypeError under caller strict_types (#17161, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);
try {
    file_exists(null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
file_exists(): Argument #1 ($filename) must be of type string, null given
