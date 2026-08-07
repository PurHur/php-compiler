--TEST--
ReflectionClass::getReadOnlyProperties() phantom — use getProperties+isReadOnly (#28516, re-#7186)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'getReadOnlyProperties=', method_exists(ReflectionClass::class, 'getReadOnlyProperties') ? '1' : '0', "\n";

readonly class R {
    public function __construct(public int $id, public string $name) {}
}

$r = new ReflectionClass(R::class);
$names = [];
foreach ($r->getProperties() as $p) {
    if ($p->isReadOnly()) {
        $names[] = $p->getName();
    }
}
sort($names);
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
echo implode(',', $roNames), "\n";
--EXPECT--
getReadOnlyProperties=0
id,name
ro
