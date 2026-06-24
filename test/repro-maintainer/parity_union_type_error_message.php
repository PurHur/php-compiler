<?php

declare(strict_types=1);

class TStringInt
{
    public string|int $x;
}

class TIntString
{
    public int|string $y;
}

foreach (
    [
        [new TStringInt(), 'TStringInt', 'x', 'string|int'],
        [new TIntString(), 'TIntString', 'y', 'string|int'],
    ] as [$obj, $class, $prop, $expectedType]
) {
    try {
        $obj->{$prop} = null;
        fwrite(STDERR, "FAIL {$class}::\${$prop}: expected TypeError\n");
        exit(1);
    } catch (TypeError $e) {
        $expected = "Cannot assign null to property {$class}::\${$prop} of type {$expectedType}";
        if ($e->getMessage() !== $expected) {
            fwrite(STDERR, "FAIL {$class}::\${$prop}\n  got: {$e->getMessage()}\n want: {$expected}\n");
            exit(1);
        }
    }
}

echo "OK\n";
