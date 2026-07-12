--TEST--
Stdlib: ReflectionParameter internal ctor default metadata (#18356, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

$p0 = new ReflectionParameter(['ArrayObject', '__construct'], 0);
echo 'ao_optional=', $p0->isOptional() ? 'yes' : 'no', "\n";
echo 'ao_avail=', $p0->isDefaultValueAvailable() ? 'yes' : 'no', "\n";
var_export($p0->getDefaultValue());
echo "\n";

$p1 = new ReflectionParameter(['ArrayObject', '__construct'], 1);
var_export($p1->getDefaultValue());
echo "\n";

$p2 = new ReflectionParameter(['ArrayObject', '__construct'], 2);
var_export($p2->getDefaultValue());
echo "\n";

$dt = new ReflectionParameter(['DateTime', '__construct'], 0);
echo 'dt_avail=', $dt->isDefaultValueAvailable() ? 'yes' : 'no', "\n";
var_export($dt->getDefaultValue());
echo "\n";

$dtz = new ReflectionParameter(['DateTime', '__construct'], 1);
var_export($dtz->getDefaultValue());
echo "\n";
?>
--EXPECT--
ao_optional=yes
ao_avail=yes
array (
)
0
'ArrayIterator'
dt_avail=yes
'now'
NULL
