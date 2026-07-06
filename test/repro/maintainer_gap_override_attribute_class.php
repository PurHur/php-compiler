<?php

declare(strict_types=1);

if (!class_exists('Override', false)) {
    echo "fail: Override builtin class missing on forward profile\n";
    exit(1);
}

if (!class_exists('Attribute', false)) {
    echo "fail: Attribute base class missing\n";
    exit(1);
}

if (!(new ReflectionClass('Override'))->isInternal()) {
    echo "fail: Override must be internal\n";
    exit(1);
}

class Base
{
    public function f(): string
    {
        return 'b';
    }
}

class Child extends Base
{
    #[\Override]
    public function f(): string
    {
        return 'c';
    }
}

echo (new Child())->f() === 'c' ? "ok\n" : "fail: override method\n";
