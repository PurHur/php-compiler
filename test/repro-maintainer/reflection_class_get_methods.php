<?php

declare(strict_types=1);

class A
{
    public function pubA(): void
    {
    }

    protected function protA(): void
    {
    }

    private function privA(): void
    {
    }

    public static function statA(): void
    {
    }
}

class B extends A
{
    public function pubB(): void
    {
    }

    private function privB(): void
    {
    }
}

$r = new ReflectionClass(B::class);
foreach ($r->getMethods() as $m) {
    echo $m->getDeclaringClass()->getName(), '::', $m->getName(), "\n";
}
echo "--- public only ---\n";
foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
    echo $m->getDeclaringClass()->getName(), '::', $m->getName(), "\n";
}
echo "--- static only ---\n";
foreach ($r->getMethods(ReflectionMethod::IS_STATIC) as $m) {
    echo $m->getDeclaringClass()->getName(), '::', $m->getName(), "\n";
}
