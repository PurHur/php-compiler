<?php
declare(strict_types=1);

if (!extension_loaded('sodium') || !function_exists('sodium_pad')) {
    fwrite(STDERR, "skip: ext/sodium or sodium_pad unavailable\n");
    exit(0);
}

$msg = 'hello world';
$block = 16;
$padded = sodium_pad($msg, $block);
$unpadded = sodium_unpad($padded, $block);

echo function_exists('sodium_pad') ? "exists_pad\n" : "missing_pad\n";
echo function_exists('sodium_unpad') ? "exists_unpad\n" : "missing_unpad\n";
echo $unpadded === $msg ? "roundtrip_ok\n" : "roundtrip_fail\n";
echo \strlen($padded) % $block === 0 ? "aligned_ok\n" : "aligned_fail\n";

try {
    sodium_unpad('short', $block);
    echo "invalid_ok\n";
} catch (\SodiumException) {
    echo "invalid_fail\n";
}
