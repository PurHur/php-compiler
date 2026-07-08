<?php

declare(strict_types=1);

$r = new ReflectionConstant('PHP_VERSION');
echo 'name=', $r->getName(), "\n";
echo 'value_type=', gettype($r->getValue()), "\n";
echo 'value_nonempty=', ('' !== (string) $r->getValue() ? 'yes' : 'no'), "\n";

class C17341
{
    public const FOO = 7;
}

$r2 = new ReflectionConstant(C17341::class, 'FOO');
echo 'class_name=', $r2->getName(), "\n";
echo 'class_value=', var_export($r2->getValue(), true), "\n";

try {
    new ReflectionConstant('DOES_NOT_EXIST_17341');
    echo "missing: no throw\n";
} catch (ReflectionException $e) {
    echo 'missing: ', $e->getMessage(), "\n";
}
