--TEST--
stdlib ReflectionEnumUnitCase::isDeprecated() — PHP 8.4 enum case #[\Deprecated] (#9864, #15767, ext/reflection/php_reflection.c)
--FILE--
<?php
enum E: int {
    #[\Deprecated(message: 'old case', since: '8.4')]
    case A = 1;
    case B = 2;
}
$rA = new ReflectionEnumUnitCase(E::class, 'A');
$rB = new ReflectionEnumUnitCase(E::class, 'B');
var_export($rA->isDeprecated());
echo "\n";
var_export($rB->isDeprecated());
echo "\n";
$rBacked = new ReflectionEnumBackedCase(E::class, 'A');
var_export($rBacked->isDeprecated());
echo "\n";
--EXPECT--
true
false
true
