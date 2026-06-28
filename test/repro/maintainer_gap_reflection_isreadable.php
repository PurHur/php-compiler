<?php

declare(strict_types=1);

class ReflectionReadablePublic {
    public int $x = 1;
}

class ReflectionReadableReadonly {
    public readonly int $y;

    public function __construct()
    {
        $this->y = 1;
    }
}

class ReflectionReadableAsymmetric {
    public private(set) int $z = 1;
}

$r = new ReflectionProperty(ReflectionReadablePublic::class, 'x');
if (!method_exists($r, 'isReadable') || !method_exists($r, 'isWritable')) {
    echo 'fail: ReflectionProperty::isReadable()/isWritable() missing', PHP_EOL;
    exit(1);
}

if (!$r->isReadable(null) || !$r->isWritable(null)) {
    echo 'fail: public $x expected readable and writable from global scope', PHP_EOL;
    exit(1);
}

$readonly = new ReflectionProperty(ReflectionReadableReadonly::class, 'y');
if (!$readonly->isReadable(null) || $readonly->isWritable(null)) {
    echo 'fail: public readonly $y expected readable true, writable false', PHP_EOL;
    exit(1);
}

$asymmetric = new ReflectionProperty(ReflectionReadableAsymmetric::class, 'z');
if (!$asymmetric->isReadable(null) || $asymmetric->isWritable(null)) {
    echo 'fail: public private(set) $z expected readable true, writable false from global scope', PHP_EOL;
    exit(1);
}

echo 'ok', PHP_EOL;
