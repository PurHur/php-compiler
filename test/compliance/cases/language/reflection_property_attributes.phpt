--TEST--
Language: ReflectionProperty attributes — getAttributes() on properties (#4136)
--FILE--
<?php
#[\Attribute]
class A { public function __construct(public string $v) {} }

class C {
    #[A('prop')]
    public int $p = 1;
}

$rp = new ReflectionProperty(C::class, 'p');
echo count($rp->getAttributes()), "\n";
echo $rp->getAttributes()[0]->getName(), "\n";

$rp2 = new ReflectionProperty(C::class, 'p');
$attrs = $rp2->getAttributes(A::class);
$args = $attrs[0]->getArguments();
echo $args[0], "\n";
?>
--EXPECT--
1
A
prop
