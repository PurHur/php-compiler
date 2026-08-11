--TEST--
stdlib finfo_open(null) TypeError under strict_types (#30258, ext/fileinfo/fileinfo.c)
--FILE--
<?php
declare(strict_types=1);
try {
    finfo_open(null);
    echo "fail_open\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    new finfo(null);
    echo "fail_ctor\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:finfo_open(): Argument #1 ($flags) must be of type int, null given
TypeError:finfo::__construct(): Argument #1 ($flags) must be of type int, null given
