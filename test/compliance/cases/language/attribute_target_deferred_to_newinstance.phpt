--TEST--
Language: user Attribute TARGET mismatch deferred to ReflectionAttribute::newInstance (#25729)
--FILE--
<?php
#[Attribute(Attribute::TARGET_CLASS)]
class A {}
class C {
    #[A]
    public int $x = 1;
}
$attrs = (new ReflectionProperty(C::class, 'x'))->getAttributes();
echo count($attrs), "\n";
try {
    $attrs[0]->newInstance();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

#[Attribute(Attribute::TARGET_METHOD)]
class MethodOnly {}
class P {
    public function __construct(
        #[MethodOnly]
        public readonly string $x,
    ) {}
}
$pattrs = (new ReflectionProperty(P::class, 'x'))->getAttributes();
echo count($pattrs), "\n";
try {
    $pattrs[0]->newInstance();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
1
Error: Attribute "A" cannot target property (allowed targets: class)
1
Error: Attribute "MethodOnly" cannot target property (allowed targets: method)
