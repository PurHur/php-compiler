<?php
// AOT compile-only (#5060): ReflectionProperty::getAsymmetricVisibility() on instance + static props.
declare(strict_types=1);

class C
{
    public (private(set)) string $name = 'x';
    public (private(set)) static int $sx = 1;
}

$r = new ReflectionProperty(C::class, 'name');
$rs = new ReflectionProperty(C::class, 'sx');
$asym = $r->getAsymmetricVisibility();
echo $asym['get'], ',', $asym['set'], "\n";
$asymS = $rs->getAsymmetricVisibility();
echo $asymS['get'], ',', $asymS['set'], "\n";
