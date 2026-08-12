--TEST--
stdlib pfsockopen(null) TypeError under strict_types (#30393, ext/standard/fsock.c)
--FILE--
<?php
declare(strict_types=1);
try {
    pfsockopen(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:pfsockopen(): Argument #1 ($hostname) must be of type string, null given
