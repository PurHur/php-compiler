<?php
declare(strict_types=1);

abstract class C {
    abstract public function m(): void;
}

$r = new ReflectionClass(C::class);
$m = $r->getMethods(ReflectionMethod::IS_ABSTRACT);
echo 'count=', count($m), "\n";
if ($m !== []) {
    echo $m[0]->getName(), "\n";
}
