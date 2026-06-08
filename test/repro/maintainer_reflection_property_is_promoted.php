<?php
class C {
    public function __construct(public int $a, public string $b) {}
}
foreach (['a', 'b'] as $name) {
    $p = new ReflectionProperty(C::class, $name);
    echo $name, ' ', $p->isPromoted() ? 'promoted' : 'not', "\n";
}

class D {
    public int $plain;
    public function __construct(public int $promoted) {}
}
$p = new ReflectionProperty(D::class, 'plain');
echo 'plain ', $p->isPromoted() ? 'promoted' : 'not', "\n";
$p = new ReflectionProperty(D::class, 'promoted');
echo 'promoted ', $p->isPromoted() ? 'promoted' : 'not', "\n";
