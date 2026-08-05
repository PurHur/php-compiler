--TEST--
openssl_cipher_key_length Reflection string $cipher_algo → int|false (VM, issue #27916)
--FILE--
<?php
$r = new ReflectionFunction('openssl_cipher_key_length');
$p = $r->getParameters();
echo 'argc=', count($p), "\n";
echo 'p0_type=', $p[0]->getType() ? (string) $p[0]->getType() : 'NULL', "\n";
echo 'p0_name=', $p[0]->getName(), "\n";
echo 'ret=', $r->getReturnType() ? (string) $r->getReturnType() : 'NULL', "\n";
echo 'val=', (string) openssl_cipher_key_length(cipher_algo: 'aes-256-cbc'), "\n";
?>
--EXPECT--
argc=1
p0_type=string
p0_name=cipher_algo
ret=int|false
val=32
