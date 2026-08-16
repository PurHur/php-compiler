--TEST--
stdlib sodium_crypto_stream_xchacha20_xor_ic() Reflection + named args (#27917)
--SKIPIF--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_stream_xchacha20_xor_ic')) {
    die('skip sodium_crypto_stream_xchacha20_xor_ic missing');
}
?>
--FILE--
<?php
$f = 'sodium_crypto_stream_xchacha20_xor_ic';
$r = new ReflectionFunction($f);
echo $f, ' arity=', $r->getNumberOfParameters(), "\n";
$names = [];
foreach ($r->getParameters() as $p) {
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    $opt = $p->isOptional() ? '?' : '';
    $names[] = $opt . $p->getName() . ':' . $type;
}
echo $f, ' params=', implode(',', $names), "\n";
echo $f, ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$key = str_repeat("\0", SODIUM_CRYPTO_STREAM_XCHACHA20_KEYBYTES);
$nonce = str_repeat("\0", SODIUM_CRYPTO_STREAM_XCHACHA20_NONCEBYTES);
$pos = $f('hi', $nonce, 0, $key);
$named = $f(message: 'hi', nonce: $nonce, counter: 0, key: $key);
echo 'named_len=', strlen($named), "\n";
echo 'same=', $pos === $named ? '1' : '0', "\n";
?>
--EXPECT--
sodium_crypto_stream_xchacha20_xor_ic arity=4
sodium_crypto_stream_xchacha20_xor_ic params=message:string,nonce:string,counter:int,key:string
sodium_crypto_stream_xchacha20_xor_ic return=string
named_len=2
same=1
