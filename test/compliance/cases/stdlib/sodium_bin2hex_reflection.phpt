--TEST--
stdlib sodium_bin2hex()/sodium_hex2bin() Reflection + named args (#27778)
--SKIPIF--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_bin2hex')) {
    die('skip sodium_bin2hex missing');
}
?>
--FILE--
<?php
foreach (['sodium_bin2hex', 'sodium_hex2bin'] as $fn) {
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
echo 'named_bin=', sodium_bin2hex(string: 'a'), "\n";
echo 'named_hex=', sodium_hex2bin(string: '61', ignore: ''), "\n";
?>
--EXPECT--
sodium_bin2hex arity=1
sodium_bin2hex params=string:string
sodium_bin2hex return=string
sodium_hex2bin arity=2
sodium_hex2bin params=string:string,?ignore:string
sodium_hex2bin return=string
named_bin=61
named_hex=a
