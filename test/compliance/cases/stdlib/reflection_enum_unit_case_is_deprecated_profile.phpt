--TEST--
ReflectionEnumUnitCase::isDeprecated() phantom on 8.2 reference profile (#15767, ext/reflection/php_reflection.c)
--FILE--
<?php
enum E: int {
    #[\Deprecated(message: 'old case', since: '8.4')]
    case A = 1;
    case B = 2;
}
$rA = new ReflectionEnumUnitCase(E::class, 'A');
$rBacked = new ReflectionEnumBackedCase(E::class, 'A');
echo 'unit=', method_exists($rA, 'isDeprecated') ? 'yes' : 'no', "\n";
echo 'backed=', method_exists($rBacked, 'isDeprecated') ? 'yes' : 'no', "\n";
--EXPECT--
unit=no
backed=no
