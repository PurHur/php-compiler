--TEST--
openssl OPENSSL_TLSEXT_SERVER_NAME (#24084)
--FILE--
<?php
declare(strict_types=1);

// Bare names (not constant()) so AOT matches VM.
$name = 'OPENSSL_TLSEXT_SERVER_NAME';
$want = 1;
$got = defined('OPENSSL_TLSEXT_SERVER_NAME') ? OPENSSL_TLSEXT_SERVER_NAME : null;
if (null === $got) {
    echo $name, "=UNDEF\n";
} else {
    echo $name, '=', $got === $want ? 'ok' : ("bad:{$got}"), "\n";
}
echo 'OPENSSL_RAW_DATA=', defined('OPENSSL_RAW_DATA') && OPENSSL_RAW_DATA === 1 ? 'ok' : 'bad', "\n";
?>
--EXPECT--
OPENSSL_TLSEXT_SERVER_NAME=ok
OPENSSL_RAW_DATA=ok
