<?php
declare(strict_types=1);

// #28185 — php-src ReflectionProperty has isPrivateSet/isProtectedSet only (no isPublicSet).
class C
{
    public private(set) int $x = 1;
}

$r = new ReflectionProperty(C::class, 'x');
echo 'isPublicSet=', method_exists($r, 'isPublicSet') ? 'yes' : 'no', "\n";
echo 'isPrivateSet=', method_exists($r, 'isPrivateSet') ? 'yes' : 'no', "\n";
echo 'isProtectedSet=', method_exists($r, 'isProtectedSet') ? 'yes' : 'no', "\n";
