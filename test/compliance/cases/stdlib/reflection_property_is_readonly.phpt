--TEST--
ReflectionProperty::isReadOnly() — readonly probe (issue #7187/#23544, ext/reflection/php_reflection.c)
--FILE--
<?php
class C {
    public readonly int $ro;
    public int $rw;
    public function __construct() { $this->ro = 1; $this->rw = 2; }
}
foreach (['ro', 'rw'] as $name) {
    $p = new ReflectionProperty(C::class, $name);
    echo $name, '=', $p->isReadOnly() ? '1' : '0', "\n";
}

class D {
    public function __construct(public readonly int $promoted) {}
}
$p = new ReflectionProperty(D::class, 'promoted');
echo 'promoted=', $p->isReadOnly() ? '1' : '0', "\n";

// Static properties are never readonly — Zend returns false, must not throw (#23544).
class A {
    private static $st = 1;
    public static int $sti = 2;
}
foreach (['st', 'sti'] as $name) {
    $p = new ReflectionProperty(A::class, $name);
    echo $name, '=', $p->isReadOnly() ? '1' : '0', "\n";
}
--EXPECT--
ro=1
rw=0
promoted=1
st=0
sti=0
