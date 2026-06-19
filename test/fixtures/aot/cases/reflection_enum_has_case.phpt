--TEST--
AOT: ReflectionEnum::hasCase() / getCase() / isBacked() (#9892, php_reflection.c)
--FILE--
<?php
enum E: string {
    case A = 'a';
}
enum U {
    case X;
}
$r = new ReflectionEnum(E::class);
var_export($r->hasCase('A'));
echo "\n";
var_export($r->hasCase('Z'));
echo "\n";
var_export($r->isBacked());
echo "\n";
echo $r->getCase('A')->getName(), "\n";
try {
    $r->getCase('Z');
    echo "no throw\n";
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}
$ru = new ReflectionEnum(U::class);
var_export($ru->isBacked());
echo "\n";
--EXPECT--
true
false
true
A
Case E::Z does not exist
false
