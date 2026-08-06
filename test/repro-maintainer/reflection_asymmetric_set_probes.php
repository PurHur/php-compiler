<?php
declare(strict_types=1);

class C
{
    public (private(set)) string $p = 'x';
}

$r = new ReflectionProperty(C::class, 'p');
// php-src has isPrivateSet/isProtectedSet only — no isPublicSet (#28185).
foreach (['isPrivateSet', 'isProtectedSet', 'isPublicSet', 'isPrivateGet'] as $method) {
    echo $method, ': ', method_exists($r, $method) ? 'yes' : 'no', "\n";
}
echo $r->isPrivateSet() ? "private-set\n" : "not-private-set\n";

class S
{
    public (protected(set)) static string $sp = 'y';
}
$rs = new ReflectionProperty(S::class, 'sp');
echo 'static_isProtectedSet: ', $rs->isProtectedSet() ? 'yes' : 'no', "\n";
echo 'static_isPublicGet: ', $rs->isPublicGet() ? 'yes' : 'no', "\n";
