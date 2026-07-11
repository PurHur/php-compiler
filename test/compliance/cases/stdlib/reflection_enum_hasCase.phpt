--TEST--
Stdlib: ReflectionEnum::hasCase() / getCase() — enum case probe API (#6930, php_reflection.c)
--FILE--
<?php
enum E: string {
    case A = 'a';
}
$r = new ReflectionEnum(E::class);
var_export($r->hasCase('A'));
echo "\n";
var_export($r->hasCase('Z'));
echo "\n";
var_export($r->isBacked());
echo "\n";
echo $r->getCase('A')->getName(), "\n";
echo $r->getCase('A')->name, "\n";
try {
    $r->getCase('Z');
    echo "no throw\n";
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
false
true
A
A
Case E::Z does not exist
