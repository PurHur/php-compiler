--TEST--
ReflectionFunction::isDeprecated() false on 8.2 reference profile (#9760, #16359, ext/reflection/php_reflection.c)
--FILE--
<?php
#[\Deprecated(message: 'old fn', since: '8.4')]
function dep(): void {}

function control(): void {}

$rf = new ReflectionFunction('dep');
echo method_exists($rf, 'isDeprecated') ? 'yes' : 'no', "\n";
var_export($rf->isDeprecated());
echo "\n";
$rc = new ReflectionFunction('control');
var_export($rc->isDeprecated());
echo "\n";
--EXPECT--
yes
false
false
