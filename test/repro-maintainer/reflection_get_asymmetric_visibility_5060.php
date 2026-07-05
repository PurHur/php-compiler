<?php
declare(strict_types=1);

class C
{
    public (private(set)) string $name = 'x';
    public (private(set)) static int $sx = 1;
    public int $plain = 0;
}

$r = new ReflectionProperty(C::class, 'name');
$rs = new ReflectionProperty(C::class, 'sx');
$rp = new ReflectionProperty(C::class, 'plain');
var_export($r->getAsymmetricVisibility());
echo "\n";
var_export($rs->getAsymmetricVisibility());
echo "\n";
var_export($rp->getAsymmetricVisibility());
echo "\n";
