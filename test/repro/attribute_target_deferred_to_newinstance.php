<?php
#[Attribute(Attribute::TARGET_CLASS)]
class A {}
class C {
    #[A]
    public int $x = 1;
}
$attrs = (new ReflectionProperty(C::class, "x"))->getAttributes();
echo count($attrs), "\n";
try {
    $attrs[0]->newInstance();
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
