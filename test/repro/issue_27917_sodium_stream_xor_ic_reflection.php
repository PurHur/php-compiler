<?php
/**
 * #27917 — sodium_crypto_stream_xchacha20_xor_ic() Reflection + named args.
 */
$f = 'sodium_crypto_stream_xchacha20_xor_ic';
$r = new ReflectionFunction($f);
$parts = [];
foreach ($r->getParameters() as $p) {
    $parts[] = ($p->hasType() ? (string) $p->getType() : '?') . ' $' . $p->getName();
}
echo 'sig=', $f, '(', implode(', ', $parts), '):', $r->hasReturnType() ? (string) $r->getReturnType() : '?', "\n";
$key = str_repeat("\0", SODIUM_CRYPTO_STREAM_XCHACHA20_KEYBYTES);
$nonce = str_repeat("\0", SODIUM_CRYPTO_STREAM_XCHACHA20_NONCEBYTES);
$pos = $f('hi', $nonce, 0, $key);
$named = $f(message: 'hi', nonce: $nonce, counter: 0, key: $key);
echo 'named_len=', (string) strlen($named), "\n";
echo 'same=', $pos === $named ? '1' : '0', "\n";
