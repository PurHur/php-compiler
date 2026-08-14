--TEST--
ReflectionClass::getConstants()/getReflectionConstants() on DateTime builtins (#30887)
--FILE--
<?php
$r = new ReflectionClass(DateTime::class);
$consts = $r->getConstants();
ksort($consts);
echo 'getConstants=', count($consts), "\n";
echo 'getReflectionConstants=', count($r->getReflectionConstants()), "\n";
echo 'ATOM_decl=', (new ReflectionClassConstant(DateTime::class, 'ATOM'))->getDeclaringClass()->getName(), "\n";
echo 'getConstant_ATOM=';
var_export($r->getConstant('ATOM'));
echo "\n";
echo 'keys=', implode(',', array_keys($consts)), "\n";
$ri = new ReflectionClass(DateTimeImmutable::class);
echo 'immutable=', count($ri->getConstants()), "\n";
class UserConstProbe { public const X = 1; }
$ru = new ReflectionClass(UserConstProbe::class);
echo 'userland=', implode(',', array_keys($ru->getConstants())), "\n";
try {
    $r->getConstants(1, 2);
    echo "excess_ok\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $r->getReflectionConstants(1, 2);
    echo "excess_ok_grc\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
getConstants=14
getReflectionConstants=14
ATOM_decl=DateTimeInterface
getConstant_ATOM='Y-m-d\\TH:i:sP'
keys=ATOM,COOKIE,ISO8601,ISO8601_EXPANDED,RFC1036,RFC1123,RFC2822,RFC3339,RFC3339_EXTENDED,RFC7231,RFC822,RFC850,RSS,W3C
immutable=14
userland=X
ReflectionClass::getConstants() expects at most 1 argument, 2 given
ReflectionClass::getReflectionConstants() expects at most 1 argument, 2 given
