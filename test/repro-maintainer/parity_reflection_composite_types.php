<?php
declare(strict_types=1);

class C {
    public function m(string|int $x, Countable&Traversable $y): void {}
}

$rm = new ReflectionMethod(C::class, 'm');
$union = $rm->getParameters()[0]->getType();
$inter = $rm->getParameters()[1]->getType();
echo get_class($union), "\n";
echo get_class($inter), "\n";
var_export(method_exists($union, 'getTypes'));
echo "\n";
var_export(method_exists($inter, 'getTypes'));
echo "\n";
foreach ($union->getTypes() as $t) {
    echo get_class($t), ' ', $t->getName(), "\n";
}
foreach ($inter->getTypes() as $t) {
    echo get_class($t), ' ', $t->getName(), "\n";
}
