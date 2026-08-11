--TEST--
stdlib fsockopen(null) TypeError under strict_types (#30313, ext/standard/fsock.c)
--FILE--
<?php
declare(strict_types=1);
try {
    fsockopen(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:fsockopen(): Argument #1 ($hostname) must be of type string, null given
