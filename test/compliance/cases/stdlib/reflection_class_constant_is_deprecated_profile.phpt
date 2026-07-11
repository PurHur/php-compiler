--TEST--
ReflectionClassConstant::isDeprecated() phantom on 8.2 reference profile (#17104, ext/reflection/php_reflection.c)
--FILE--
<?php
class C {
    #[\Deprecated(message: 'Old const', since: '8.4')]
    public const X = 1;
    public const Y = 2;
}
$rc = new ReflectionClassConstant(C::class, 'X');
echo method_exists($rc, 'isDeprecated') ? 'yes' : 'no', "\n";
--EXPECT--
no
