--TEST--
stdlib ReflectionEnumUnitCase::isDeprecated() — #[\Deprecated] enum case on 8.4 profile (#16821, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
enum E: int {
    #[\Deprecated(message: 'old case', since: '8.4')]
    case A = 1;
    case B = 2;
}
$rA = new ReflectionEnumUnitCase(E::class, 'A');
$rB = new ReflectionEnumUnitCase(E::class, 'B');
echo $rA->isDeprecated() ? 'deprecated' : 'not_deprecated', "\n";
echo $rB->isDeprecated() ? 'deprecated' : 'not_deprecated', "\n";
--EXPECT--
not_deprecated
not_deprecated
