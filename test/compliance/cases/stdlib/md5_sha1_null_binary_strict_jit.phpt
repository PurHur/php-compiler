--TEST--
JIT: md5/sha1(null $binary) TypeError under strict_types (#31358, ext/standard/md5.c)
--FILE--
<?php
declare(strict_types=1);
try {
    md5('x', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    sha1('x', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
md5(): Argument #2 ($binary) must be of type bool, null given
sha1(): Argument #2 ($binary) must be of type bool, null given
