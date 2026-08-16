--TEST--
stdlib sodium_crypto_aead_aes256gcm_is_available() Reflection return bool (#27775)
--SKIPIF--
<?php if (!extension_loaded('sodium') || !function_exists('sodium_crypto_aead_aes256gcm_is_available')) { die('skip sodium AES-GCM probe missing'); } ?>
--FILE--
<?php
$fn = 'sodium_crypto_aead_aes256gcm_is_available';
$r = new ReflectionFunction($fn);
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
echo 'arity=', $r->getNumberOfParameters(), "\n";
$v = $fn();
echo 'val_type=', gettype($v), "\n";
?>
--EXPECT--
return=bool
arity=0
val_type=boolean
