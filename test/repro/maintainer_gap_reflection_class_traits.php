<?php

trait Tr
{
    public function x(): int
    {
        return 1;
    }
}

interface Iface
{
}

enum E implements Iface
{
    case A;
    use Tr;
}

$r = new ReflectionClass(E::class);
var_export($r->getTraitNames());
echo "\n";
var_export($r->getInterfaceNames());
echo "\n";
