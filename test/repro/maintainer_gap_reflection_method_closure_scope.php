<?php

declare(strict_types=1);

class ReflectionMethodClosureScopeProbe
{
    public function m(): void
    {
    }
}

$rm = new ReflectionMethod(ReflectionMethodClosureScopeProbe::class, 'm');
if (!method_exists($rm, 'getClosureScopeClass') || !method_exists($rm, 'getClosureThis')) {
    fwrite(STDERR, "fail: missing closure introspection methods\n");
    exit(1);
}
if (null !== $rm->getClosureScopeClass() || null !== $rm->getClosureThis()) {
    fwrite(STDERR, "fail: plain method should return null\n");
    exit(1);
}

echo "ok\n";
