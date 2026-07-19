--TEST--
AOT: openssl_digest() sha256 hex + raw (#21081)
--FILE--
<?php
declare(strict_types=1);

echo openssl_digest('abc', 'sha256'), "\n";
echo bin2hex(openssl_digest('abc', 'sha256', true)), "\n";
--EXPECT--
ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad
ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad
