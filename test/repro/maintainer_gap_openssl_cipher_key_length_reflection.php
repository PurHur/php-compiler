<?php
/**
 * Issue #27916 — openssl_cipher_key_length Reflection string $cipher_algo → int|false.
 * php-src: ext/openssl/openssl.stub.php
 */
$r = new ReflectionFunction('openssl_cipher_key_length');
$p = $r->getParameters();
echo 'argc=', count($p), PHP_EOL;
echo 'p0_type=', $p[0]->getType() ? (string) $p[0]->getType() : 'NULL', PHP_EOL;
echo 'p0_name=', $p[0]->getName(), PHP_EOL;
echo 'ret=', $r->getReturnType() ? (string) $r->getReturnType() : 'NULL', PHP_EOL;
echo 'val=', (string) openssl_cipher_key_length(cipher_algo: 'aes-256-cbc'), PHP_EOL;
