<?php
declare(strict_types=1);

class DepProp {
    #[\Deprecated]
    public int $p = 0;
}
$rp = new ReflectionProperty(DepProp::class, 'p');
echo 'ReflectionProperty::isDeprecated exists: ', var_export(method_exists($rp, 'isDeprecated'), true), "\n";
echo 'ReflectionProperty::isDeprecated: ', var_export($rp->isDeprecated(), true), "\n";

function dep_param(#[\Deprecated] string $x): void {}
$rparam = new ReflectionParameter('dep_param', 'x');
echo 'ReflectionParameter::isDeprecated exists: ', var_export(method_exists($rparam, 'isDeprecated'), true), "\n";
echo 'ReflectionParameter::isDeprecated: ', var_export($rparam->isDeprecated(), true), "\n";

function plain_param(string $x): void {}
$plain = new ReflectionParameter('plain_param', 'x');
echo 'plain isDeprecated: ', var_export($plain->isDeprecated(), true), "\n";
