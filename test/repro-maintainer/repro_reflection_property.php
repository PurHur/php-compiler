<?php
declare(strict_types=1);

#[\Attribute]
final class A {
    public function __construct(public string $x) {}
}

class C {
    #[A('p')]
    public readonly int $p;
}

$rp = new ReflectionProperty(C::class, 'p');
echo $rp->getName(), "\n";
echo ($rp->isPublic() ? 'public' : 'other'), ' readonly=', ($rp->isReadonly() ? '1' : '0'), "\n";
$t = $rp->getType();
echo ($t ? $t->__toString() : 'no-type'), "\n";
$attrs = $rp->getAttributes(A::class);
echo count($attrs), "\n";
if ($attrs) {
    echo $attrs[0]->newInstance()->x, "\n";
}
