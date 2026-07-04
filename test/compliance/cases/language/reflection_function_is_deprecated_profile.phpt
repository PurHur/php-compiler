--TEST--
ReflectionFunction::isDeprecated() phantom on 8.2 reference profile (#9760, ext/reflection/php_reflection.c)
--FILE--
<?php
#[\Deprecated(message: 'old fn', since: '8.4')]
function dep(): void {}

$rf = new ReflectionFunction('dep');
echo method_exists($rf, 'isDeprecated') ? 'yes' : 'no', "\n";
--EXPECT--
no
