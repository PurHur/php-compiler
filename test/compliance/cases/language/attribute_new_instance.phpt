--TEST--
Language: ReflectionAttribute::newInstance() on class attributes (#3206)
--FILE--
<?php
#[Attribute]
class Route {
    public function __construct(public string $path) {}
}
#[Route('/home')]
class C {}
$a = (new ReflectionClass(C::class))->getAttributes()[0];
$o1 = $a->newInstance();
$o2 = $a->newInstance();
var_dump($o1 === $o2);
echo $o1->path;
--EXPECT--
bool(false)
/home
