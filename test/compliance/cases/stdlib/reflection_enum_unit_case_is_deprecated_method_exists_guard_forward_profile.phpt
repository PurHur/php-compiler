--TEST--
ReflectionEnumUnitCase::isDeprecated() after method_exists guard — property metadata survives (#16331, ext/reflection/php_reflection.c)
--FILE--
<?php
enum E: int {
    #[\Deprecated(message: 'old case', since: '8.4')]
    case A = 1;
    case B = 2;
}
$rA = new ReflectionEnumUnitCase(E::class, 'A');
$rB = new ReflectionEnumUnitCase(E::class, 'B');
var_export(method_exists($rA, 'isDeprecated'));
echo "\n";
if (method_exists($rA, 'isDeprecated')) {
    var_export($rA->isDeprecated());
    echo "\n";
    var_export($rB->isDeprecated());
    echo "\n";
}
--EXPECT--
true
false
false
