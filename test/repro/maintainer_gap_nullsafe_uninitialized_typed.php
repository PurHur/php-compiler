<?php

class B {
    public string $x = 'ok';
}

class A {
    public ?B $b;
}

$a = new A();
echo $a?->b?->x ?? 'n', "\n";
