<?php

declare(strict_types=1);

class ReflectionGetDefaultC
{
    public int $count = 5;
    public int $unset;
}

$prop = new ReflectionProperty(ReflectionGetDefaultC::class, 'count');
if (5 !== $prop->getDefaultValue()) {
    echo 'bad:', var_export($prop->getDefaultValue(), true), "\n";
    exit(1);
}
$none = new ReflectionProperty(ReflectionGetDefaultC::class, 'unset');
if (null !== $none->getDefaultValue()) {
    echo 'unset_not_null:', var_export($none->getDefaultValue(), true), "\n";
    exit(1);
}
echo "default_ok\n";
