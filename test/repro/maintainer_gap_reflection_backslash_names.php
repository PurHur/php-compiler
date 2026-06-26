<?php

declare(strict_types=1);

// Maintainer gap #12191 — reflection constructors with leading backslash (php-src-strict).

class GapReflect
{
    public int $prop = 1;
}

function gap_reflect_fn(): void
{
}

$rc = new ReflectionClass('\\GapReflect');
if ('GapReflect' !== $rc->getName()) {
    fwrite(STDERR, "fail: ReflectionClass(backslash)\n");
    exit(1);
}

$rp = new ReflectionProperty('\\GapReflect', 'prop');
if ('prop' !== $rp->getName()) {
    fwrite(STDERR, "fail: ReflectionProperty(backslash)\n");
    exit(1);
}

$rf = new ReflectionFunction('\\gap_reflect_fn');
if ('gap_reflect_fn' !== $rf->getName()) {
    fwrite(STDERR, "fail: ReflectionFunction(backslash)\n");
    exit(1);
}

echo "ok\n";
