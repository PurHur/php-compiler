--TEST--
stdlib sodium_crypto_aead_aegis128l_* / aegis256_* AEAD roundtrip (#20518)
--FILE--
<?php
declare(strict_types=1);

if (!function_exists('sodium_crypto_aead_aegis128l_encrypt')) {
    echo "128l=skip\n";
} else {
    $key = sodium_crypto_aead_aegis128l_keygen();
    $npub = random_bytes(SODIUM_CRYPTO_AEAD_AEGIS128L_NPUBBYTES);
    $msg = 'secret';
    $ad = 'meta';
    $ct = sodium_crypto_aead_aegis128l_encrypt($msg, $ad, $npub, $key);
    $pt = sodium_crypto_aead_aegis128l_decrypt($ct, $ad, $npub, $key);
    $ok = ($pt === $msg);
    $bad = $ct;
    $bad[0] = chr(ord($bad[0]) ^ 0xff);
    $tamper = (false === sodium_crypto_aead_aegis128l_decrypt($bad, $ad, $npub, $key));
    echo ($ok && $tamper) ? "128l=ok\n" : "128l=fail\n";
}

if (!function_exists('sodium_crypto_aead_aegis256_encrypt')) {
    echo "256=skip\n";
} else {
    $key2 = sodium_crypto_aead_aegis256_keygen();
    $npub2 = random_bytes(SODIUM_CRYPTO_AEAD_AEGIS256_NPUBBYTES);
    $msg2 = 'secret';
    $ad2 = 'meta';
    $ct2 = sodium_crypto_aead_aegis256_encrypt($msg2, $ad2, $npub2, $key2);
    $pt2 = sodium_crypto_aead_aegis256_decrypt($ct2, $ad2, $npub2, $key2);
    $ok2 = ($pt2 === $msg2);
    $bad2 = $ct2;
    $bad2[0] = chr(ord($bad2[0]) ^ 0xff);
    $tamper2 = (false === sodium_crypto_aead_aegis256_decrypt($bad2, $ad2, $npub2, $key2));
    echo ($ok2 && $tamper2) ? "256=ok\n" : "256=fail\n";
}
--EXPECTF--
128l=%s
256=%s
