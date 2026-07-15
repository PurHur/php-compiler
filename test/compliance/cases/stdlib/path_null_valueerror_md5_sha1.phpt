--TEST--
stdlib md5_file/sha1_file/hash_file null path — ValueError on 8.4 forward profile (#19146, ext/standard/md5.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$expected = 'Path cannot be empty';
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
    } catch (ValueError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
md5_file:Path cannot be empty
sha1_file:Path cannot be empty
hash_file:Path cannot be empty
hash_hmac_file:Path cannot be empty
