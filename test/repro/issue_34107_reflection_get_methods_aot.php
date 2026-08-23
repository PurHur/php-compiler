<?php
// Repro #34107 — ReflectionClass::getMethods thin AOT
class GmParent
{
    public function pub()
    {
    }

    protected function prot()
    {
    }

    private function priv()
    {
    }
}

class GmChild extends GmParent
{
    public function child()
    {
    }

    public static function st()
    {
    }
}

$r = new ReflectionClass(GmChild::class);
$names = [];
foreach ($r->getMethods() as $m) {
    $names[] = $m->getName();
}
sort($names);
echo implode(',', $names), '|';
$pub = [];
foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
    $pub[] = $m->getName();
}
sort($pub);
echo implode(',', $pub), '|';
$null = [];
foreach ($r->getMethods(null) as $m) {
    $null[] = $m->getName();
}
sort($null);
echo implode(',', $null), "\n";
