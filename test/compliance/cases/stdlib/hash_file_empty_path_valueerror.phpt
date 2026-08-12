--TEST--
stdlib hash file builtins empty path — ValueError Path cannot be empty (#14074, ext/standard/md5.c)
--FILE--
<?php
foreach ([
    'md5_file' => static fn () => md5_file(''),
    'sha1_file' => static fn () => sha1_file(''),
    'hash_file' => static fn () => hash_file('md5', ''),
    'hash_hmac_file' => static fn () => hash_hmac_file('md5', '', 'key'),
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
hash_file:Path cannot be empty
hash_hmac_file:Path cannot be empty
