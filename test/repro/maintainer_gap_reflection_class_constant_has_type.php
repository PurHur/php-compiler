<?php

declare(strict_types=1);

/**
 * Maintainer repro: ReflectionClassConstant::hasType() (#17359).
 */
class C
{
    public const int N = 42;
    public const U = 1;
}

$typed = new ReflectionClassConstant(C::class, 'N');
if (!method_exists($typed, 'hasType')) {
    fwrite(STDERR, "fail: ReflectionClassConstant::hasType() missing\n");
    exit(1);
}
if (!$typed->hasType()) {
    fwrite(STDERR, "fail: typed const N should hasType()=true\n");
    exit(1);
}
$untyped = new ReflectionClassConstant(C::class, 'U');
if ($untyped->hasType()) {
    fwrite(STDERR, "fail: untyped const U should hasType()=false\n");
    exit(1);
}
echo "ok\n";
