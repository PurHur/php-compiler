--TEST--
stdlib md5_file/sha1_file/hash_file null path JIT — empty-path ValueError on 8.4 (#21235, ext/standard/md5.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['md5_file', 'sha1_file', 'hash_file', 'hash_hmac_file'] as $fn) {
    try {
        if ('hash_file' === $fn) {
            $fn('md5', null);
        } elseif ('hash_hmac_file' === $fn) {
            $fn('md5', null, 'key');
        } else {
            $fn(null);
        }
        echo $fn, ": miss\n";
    } catch (TypeError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    } catch (ValueError $e) {
        echo $fn, ':VALUEERROR:', $e->getMessage(), "\n";
    }
}
try {
    md5_file('');
    echo "empty: miss\n";
} catch (ValueError $e) {
    echo 'empty:', $e->getMessage(), "\n";
}
?>
--EXPECT--
md5_file:VALUEERROR:Path must not be empty
sha1_file:VALUEERROR:Path must not be empty
hash_file:VALUEERROR:Path must not be empty
hash_hmac_file:VALUEERROR:Path must not be empty
empty:Path must not be empty
