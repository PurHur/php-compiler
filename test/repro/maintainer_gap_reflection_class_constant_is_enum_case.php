<?php
/**
 * Maintainer repro: ReflectionClassConstant::isEnumCase() on enum-valued typed const (#17361).
 *
 * php-src: ext/reflection/php_reflection.c — reflection_class_constant_is_enum_case()
 */
declare(strict_types=1);

enum Color17361 {
    case Red;
}

class D17361 {
    public const Color17361 SWATCH = Color17361::Red;
    public const int PLAIN = 1;
}

$enumValued = new ReflectionClassConstant(D17361::class, 'SWATCH');
$plain = new ReflectionClassConstant(D17361::class, 'PLAIN');

echo ($enumValued->isEnumCase() ? 'enum' : 'scalar'), "\n";
echo ($plain->isEnumCase() ? 'enum' : 'scalar'), "\n";
