<?php

declare(strict_types=1);

$r = new ReflectionFunction('is_subclass_of');
$p = $r->getParameters()[2];
echo 'name=', $p->getName(), "\n";
echo 'default=', var_export($p->getDefaultValue(), true), "\n";

class A
{
}
class B extends A
{
}
echo 'runtime_omit=', var_export(is_subclass_of('B', 'A'), true), "\n";
echo 'named=', var_export(is_subclass_of(object_or_class: 'B', class: 'A', allow_string: true), true), "\n";

$isA = (new ReflectionFunction('is_a'))->getParameters()[2];
echo 'is_a_default=', var_export($isA->getDefaultValue(), true), "\n";
