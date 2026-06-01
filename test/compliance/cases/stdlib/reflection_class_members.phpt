--TEST--
Stdlib: ReflectionClass::getProperties() / getMethods() member enumeration (VM, #3815)
--FILE--
<?php
class C {
    private int $x = 1;
    public int $y = 2;
    public function m(): void {}
}
class D extends C {
    public int $z = 3;
}

$r = new ReflectionClass(C::class);
$props = [];
foreach ($r->getProperties() as $p) {
    $props[] = $p->getName();
}
sort($props);
echo implode(',', $props), "\n";

$methods = [];
foreach ($r->getMethods() as $m) {
    $methods[] = $m->getName();
}
sort($methods);
echo implode(',', $methods), "\n";

$rd = new ReflectionClass(D::class);
$propsD = [];
foreach ($rd->getProperties() as $p) {
    $propsD[] = $p->getName();
}
sort($propsD);
echo implode(',', $propsD), "\n";
--EXPECT--
x,y
m
x,y,z
