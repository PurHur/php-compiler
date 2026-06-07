--TEST--
ReflectionProperty::isReadOnly() — readonly probe (issue #7187, ext/reflection/php_reflection.c)
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
--EXPECT--
ro=1
rw=0
promoted=1
