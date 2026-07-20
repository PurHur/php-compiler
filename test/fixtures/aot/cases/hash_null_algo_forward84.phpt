--TEST--
AOT: hash()/hash_hmac(null $algo) soft-null on 8.4 — no TypeError (#21490)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$a = null;
try {
    $r = hash($a, 'x');
    echo (false === $r || is_string($r)) ? "hash:soft\n" : "hash:other\n";
} catch (TypeError $e) {
    echo "hash:TE\n";
}
try {
    $r = hash_hmac($a, 'x', 'k');
    echo (false === $r || is_string($r)) ? "hmac:soft\n" : "hmac:other\n";
} catch (TypeError $e) {
    echo "hmac:TE\n";
}
--EXPECT--
hash:soft
hmac:soft
