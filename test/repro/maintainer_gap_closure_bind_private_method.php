<?php

declare(strict_types=1);

class A
{
    private function f(): string
    {
        return 'ok';
    }
}

$c = Closure::bind(function (): string {
    return $this->f();
}, new A(), A::class);
echo $c(), "\n";
