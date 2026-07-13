--TEST--
ReflectionFunction / ReflectionFunctionAbstract — basic API and hierarchy (#4467, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

function rf4467(int $x, $y = 3): int
{
    return $x + $y;
}

$r = new ReflectionFunction('rf4467');
echo $r->getName(), "\n";
echo count($r->getParameters()), "\n";
$p0 = $r->getParameters()[0];
echo $p0->getName(), "\n";
var_export($p0->hasType());
echo "\n";
var_export(class_exists('ReflectionFunction'));
echo "\n";
var_export(class_exists('ReflectionFunctionAbstract'));
echo "\n";
var_export(is_subclass_of('ReflectionFunction', 'ReflectionFunctionAbstract'));
echo "\n";
var_export(is_subclass_of('ReflectionMethod', 'ReflectionFunctionAbstract'));
echo "\n";
?>
--EXPECT--
rf4467
2
x
true
true
true
true
true
