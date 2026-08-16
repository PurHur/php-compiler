--TEST--
stdlib sodium_pad()/sodium_unpad() Reflection + named args (#27734)
--SKIPIF--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_pad')) {
    die('skip sodium_pad missing');
}
?>
--FILE--
<?php
foreach (['sodium_pad', 'sodium_unpad'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' arity=', $r->getNumberOfParameters(), "\n";
    $names = [];
    foreach ($r->getParameters() as $p) {
        $type = $p->hasType() ? (string) $p->getType() : 'none';
        $names[] = $p->getName() . ':' . $type;
    }
    echo $fn, ' params=', implode(',', $names), "\n";
    echo $fn, ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
}
$padded = sodium_pad(string: 'hi', block_size: 16);
echo 'named_len=', strlen($padded), "\n";
echo 'unpad=', sodium_unpad(string: $padded, block_size: 16), "\n";
?>
--EXPECT--
sodium_pad arity=2
sodium_pad params=string:string,block_size:int
sodium_pad return=string
sodium_unpad arity=2
sodium_unpad params=string:string,block_size:int
sodium_unpad return=string
named_len=16
unpad=hi
