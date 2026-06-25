<?php

declare(strict_types=1);

class ReflectionHasDefaultC
{
    public int $withDefault = 7;
    public int $without;
}

$with = new ReflectionProperty(ReflectionHasDefaultC::class, 'withDefault');
$without = new ReflectionProperty(ReflectionHasDefaultC::class, 'without');
if (!$with->hasDefaultValue() || $without->hasDefaultValue()) {
    echo 'has_fail with=', var_export($with->hasDefaultValue(), true),
        ' without=', var_export($without->hasDefaultValue(), true), "\n";
    exit(1);
}
if (method_exists($with, 'isDefaultValueAvailable')) {
    if (!$with->isDefaultValueAvailable() || $without->isDefaultValueAvailable()) {
        echo 'avail_fail', "\n";
        exit(1);
    }
}
echo "flags_ok\n";
