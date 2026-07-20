--TEST--
openssl openssl_digest(null) soft-null on 8.4 forward profile (#21517, reverts #20207, ext/openssl/openssl.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$deps = [];
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (E_DEPRECATED === $n) {
        $deps[] = $m;
    }

    return true;
});
$empty = openssl_digest('', 'sha256');
$null = openssl_digest(null, 'sha256');
echo 'same='.(($empty === $null) ? '1' : '0')."\n";
echo 'digest=', $null, "\n";
echo 'dep=', (isset($deps[0]) && false !== strpos($deps[0], 'openssl_digest(): Passing null to parameter #1 ($data)')) ? '1' : '0', "\n";
?>
--EXPECT--
same=1
digest=e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
dep=1
