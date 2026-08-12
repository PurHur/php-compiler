--TEST--
stdlib md5_file/sha1_file/hash_hmac_file empty path — Path cannot be empty (#30487)
--FILE--
<?php
foreach ([
    'md5_file' => static fn () => md5_file(''),
    'sha1_file' => static fn () => sha1_file(''),
    'hash_hmac_file' => static fn () => hash_hmac_file('sha256', '', 'key'),
] as $fn => $call) {
    try {
        $call();
        echo $fn, ": miss\n";
    } catch (ValueError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
md5_file:Path cannot be empty
sha1_file:Path cannot be empty
hash_hmac_file:Path cannot be empty
