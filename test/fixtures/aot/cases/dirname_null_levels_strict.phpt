--TEST--
AOT: dirname(null $levels) TypeError under strict_types (#31210, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
try {
    dirname('/a/b/c', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
dirname(): Argument #2 ($levels) must be of type int, null given
