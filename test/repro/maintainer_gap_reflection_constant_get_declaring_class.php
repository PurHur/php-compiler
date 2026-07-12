<?php
declare(strict_types=1);

class MaintainerReflectionConstantDeclaringClass {
    public const FOO = 1;
}

$rc = new ReflectionConstant(MaintainerReflectionConstantDeclaringClass::class, 'FOO');
if (!method_exists($rc, 'getDeclaringClass')) {
    echo "missing getDeclaringClass\n";
    exit(1);
}
$name = $rc->getDeclaringClass()->getName();
if ($name !== MaintainerReflectionConstantDeclaringClass::class) {
    echo "wrong declaring class: {$name}\n";
    exit(1);
}
echo "ok\n";
