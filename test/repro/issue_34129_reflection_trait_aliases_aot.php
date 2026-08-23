<?php
// Repro #34129 — ReflectionClass::getTraitAliases thin AOT
trait T34129
{
    public function t(): int
    {
        return 1;
    }
}

class C34129
{
    use T34129 {
        t as aliasT;
    }
}

class Empty34129
{
}

$r = new ReflectionClass(C34129::class);
echo json_encode($r->getTraitAliases()), "\n";

$e = new ReflectionClass(Empty34129::class);
echo json_encode($e->getTraitAliases()), "\n";

$s = new ReflectionClass(stdClass::class);
echo json_encode($s->getTraitAliases()), "\n";
