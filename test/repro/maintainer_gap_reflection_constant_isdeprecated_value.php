<?php
declare(strict_types=1);

class C {
    /** @deprecated */
    public const X = 1;
}

$r = new ReflectionClassConstant(C::class, 'X');
if (!$r->isDeprecated()) {
    fwrite(STDERR, "expected isDeprecated true\n");
    exit(1);
}
echo "ok\n";
