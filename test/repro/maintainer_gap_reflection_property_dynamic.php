<?php
declare(strict_types=1);

if (!method_exists(ReflectionProperty::class, 'isDynamic')) {
    echo "ok\n";
    exit(0);
}

$o = new stdClass();
$o->x = 42;

$p = new ReflectionProperty($o, 'x');
echo $p->getName(), "\n";
echo $p->getValue($o), "\n";
echo var_export($p->isDynamic(), true), "\n";
echo var_export($p->isPublic(), true), "\n";

$p->setValue($o, 99);
echo $o->x, "\n";

try {
    new ReflectionProperty(stdClass::class, 'missing');
    echo "class_form: unexpected ok\n";
} catch (ReflectionException $e) {
    echo "class_form: ReflectionException\n";
}

echo "ok\n";
