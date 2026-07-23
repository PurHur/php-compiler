--TEST--
ReflectionClass::getReadOnlyProperties() — readonly property list (issue #7186, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
readonly class R {
    public function __construct(public int $id, public string $name) {}
}

$r = new ReflectionClass(R::class);
var_export(method_exists($r, 'getReadOnlyProperties'));
echo "\n";
$props = $r->getReadOnlyProperties();
echo count($props), "\n";
$names = [];
foreach ($props as $p) {
    $names[] = $p->getName();
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
foreach ($c->getReadOnlyProperties() as $p) {
    $roNames[] = $p->getName();
}
sort($roNames);
echo implode(',', $roNames), "\n";
--EXPECT--
true
2
id,name
ro
