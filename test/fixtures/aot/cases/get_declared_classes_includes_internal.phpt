--TEST--
AOT get_declared_classes() — includes CE_INTERNAL builtin classes (#11813)
--FILE--
<?php
declare(strict_types=1);

$required = ['stdClass', 'Exception', 'Error', 'Closure', 'Generator', 'ArrayObject', 'WeakMap', 'WeakReference'];
$classes = get_declared_classes();
$missing = [];
foreach ($required as $name) {
    if (!in_array($name, $classes, true)) {
        $missing[] = $name;
    }
}
echo [] === $missing ? 'ok' : 'missing=' . implode(',', $missing);
echo "\n";
--EXPECT--
ok
