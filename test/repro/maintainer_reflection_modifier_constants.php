<?php
declare(strict_types=1);

// Issue #22128 — Reflection* IS_* modifier class constants (php_reflection.stub.php).
$pairs = [
    ['ReflectionMethod', 'IS_ABSTRACT', 64],
    ['ReflectionMethod', 'IS_PUBLIC', 1],
    ['ReflectionMethod', 'IS_PROTECTED', 2],
    ['ReflectionMethod', 'IS_PRIVATE', 4],
    ['ReflectionMethod', 'IS_STATIC', 16],
    ['ReflectionMethod', 'IS_FINAL', 32],
    ['ReflectionFunction', 'IS_DEPRECATED', 2048],
    ['ReflectionProperty', 'IS_READONLY', 128],
    ['ReflectionProperty', 'IS_PUBLIC', 1],
    ['ReflectionProperty', 'IS_STATIC', 16],
    ['ReflectionClass', 'IS_IMPLICIT_ABSTRACT', 16],
    ['ReflectionClass', 'IS_EXPLICIT_ABSTRACT', 64],
    ['ReflectionClass', 'IS_FINAL', 32],
    ['ReflectionClass', 'IS_READONLY', 65536],
];

foreach ($pairs as [$class, $name, $want]) {
    $rc = new ReflectionClass($class);
    $has = $rc->hasConstant($name);
    $got = $has ? $rc->getConstant($name) : null;
    echo $class, '::', $name, ' has=', $has ? 'Y' : 'N', ' val=', var_export($got, true), ' want=', $want, "\n";
    if (!$has || $got !== $want) {
        fwrite(STDERR, "FAIL\n");
        exit(1);
    }
}
echo "OK\n";
