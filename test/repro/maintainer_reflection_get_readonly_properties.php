<?php
/**
 * #7186 / #28516 — getReadOnlyProperties is phantom; filter getProperties() + isReadOnly().
 */
readonly class R {
    public function __construct(public int $id, public string $name) {}
}

$r = new ReflectionClass(R::class);
var_export(method_exists($r, 'getReadOnlyProperties'));
echo "\n";
$names = [];
foreach ($r->getProperties() as $p) {
    if ($p->isReadOnly()) {
        $names[] = $p->getName();
    }
}
sort($names);
echo count($names), "\n";
echo implode(',', $names), "\n";

class C {
    public readonly int $ro;
    public int $rw;

    public function __construct()
    {
        $this->ro = 1;
        $this->rw = 2;
    }
}

$c = new ReflectionClass(C::class);
$roNames = [];
foreach ($c->getProperties() as $p) {
    if ($p->isReadOnly()) {
        $roNames[] = $p->getName();
    }
}
sort($roNames);
foreach ($roNames as $n) {
    echo $n, "\n";
}
