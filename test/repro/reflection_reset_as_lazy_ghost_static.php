<?php

declare(strict_types=1);

class Svc
{
    public function __construct(public int $x = 0)
    {
    }
}

$plain = new Svc(5);
ReflectionClass::resetAsLazyGhost($plain, static function (Svc $o): void {
    $o->x = 99;
});
var_dump($plain->x);
