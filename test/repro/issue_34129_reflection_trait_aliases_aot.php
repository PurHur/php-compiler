<?php
// Repro #34129 — ReflectionClass::getTraitAliases thin AOT
trait T34129
{
    public function t()
    {
    }

    public function u()
    {
    }
}
class C34129
{
    use T34129 {
        t as aliasT;
    }
}
class D34129
{
    use T34129;
}

$r = new ReflectionClass(C34129::class);
echo json_encode($r->getTraitAliases()), "\n";

$d = new ReflectionClass(D34129::class);
echo json_encode($d->getTraitAliases()), "\n";

$s = new ReflectionClass(stdClass::class);
echo json_encode($s->getTraitAliases()), "\n";
