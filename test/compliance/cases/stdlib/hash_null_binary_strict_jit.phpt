--TEST--
stdlib hash(null $binary) TypeError under strict_types JIT (#31288, ext/hash/hash.c)
--FILE--
<?php
declare(strict_types=1);
try {
    hash('md5', 'a', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
hash(): Argument #3 ($binary) must be of type bool, null given
