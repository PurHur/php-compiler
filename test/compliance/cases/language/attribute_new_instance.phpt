--TEST--
Language: ReflectionAttribute::newInstance with ctor args (#3206)
--FILE--
<?php
#[Attribute]
class Route {
    public function __construct(public string $path) {}
}
#[Route('/home')]
class C {}
$a = (new ReflectionClass(C::class))->getAttributes()[0];
$o = $a->newInstance();
echo $o->path;
--EXPECT--
/home
