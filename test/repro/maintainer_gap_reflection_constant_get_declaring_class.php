<?php
declare(strict_types=1);

// #28156 — ReflectionConstant must not expose getDeclaringClass (php-src stub).
class MaintainerReflectionConstantDeclaringClass {
    public const FOO = 1;
}

$rc = new ReflectionConstant(MaintainerReflectionConstantDeclaringClass::class, 'FOO');
if (method_exists($rc, 'getDeclaringClass')) {
    echo "unexpected getDeclaringClass on ReflectionConstant\n";
    exit(1);
}

$rcc = new ReflectionClassConstant(MaintainerReflectionConstantDeclaringClass::class, 'FOO');
if (!method_exists($rcc, 'getDeclaringClass')) {
    echo "missing getDeclaringClass on ReflectionClassConstant\n";
    exit(1);
}
$name = $rcc->getDeclaringClass()->getName();
if ($name !== MaintainerReflectionConstantDeclaringClass::class) {
    echo "wrong declaring class: {$name}\n";
    exit(1);
}
echo "ok\n";
