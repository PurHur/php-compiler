--TEST--
Language: ReflectionParameter attributes — getAttributes() on parameters (#3340)
--FILE--
<?php
#[\Attribute]
class Route { public function __construct(public string $path) {} }

class C {
    public function m(#[Route('/x')] string $id) {}
}

$rp = new ReflectionMethod(C::class, 'm');
$params = $rp->getParameters();
$attrs = $params[0]->getAttributes(Route::class);
echo count($attrs), "\n";
echo $attrs[0]->getName(), "\n";

$rp2 = new ReflectionMethod(C::class, 'm');
$attrs2 = $rp2->getParameters()[0]->getAttributes(Route::class);
$args = $attrs2[0]->getArguments();
echo $args[0], "\n";
?>
--EXPECT--
1
Route
/x
