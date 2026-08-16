--TEST--
stdlib sodium_bin2base64()/sodium_base642bin() Reflection + named args (#27853)
--SKIPIF--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_bin2base64')) {
    die('skip sodium_bin2base64 missing');
}
?>
--FILE--
<?php
foreach (['sodium_bin2base64', 'sodium_base642bin'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' arity=', $r->getNumberOfParameters(), "\n";
    $names = [];
    foreach ($r->getParameters() as $p) {
        $type = $p->hasType() ? (string) $p->getType() : 'none';
        $opt = $p->isOptional() ? '?' : '';
        $names[] = $opt . $p->getName() . ':' . $type;
    }
    echo $fn, ' params=', implode(',', $names), "\n";
    echo $fn, ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
}
$enc = sodium_bin2base64(string: 'hello', id: SODIUM_BASE64_VARIANT_ORIGINAL);
echo 'named_bin=', $enc, "\n";
echo 'named_hex=', sodium_base642bin(string: $enc, id: SODIUM_BASE64_VARIANT_ORIGINAL, ignore: ''), "\n";
?>
--EXPECT--
sodium_bin2base64 arity=2
sodium_bin2base64 params=string:string,id:int
sodium_bin2base64 return=string
sodium_base642bin arity=3
sodium_base642bin params=string:string,id:int,?ignore:string
sodium_base642bin return=string
named_bin=aGVsbG8=
named_hex=hello
