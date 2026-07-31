--TEST--
Language: delayed attribute target — promoted parameter wrong TARGET_* deferred to newInstance (#5124, #25729)
--FILE--
<?php
#[Attribute(Attribute::TARGET_METHOD)]
class MethodOnly {}

class C {
    public function __construct(
        #[MethodOnly]
        public readonly string $x,
    ) {}
}
$attrs = (new ReflectionProperty(C::class, 'x'))->getAttributes();
echo count($attrs), "\n";
try {
    $attrs[0]->newInstance();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
1
Error: Attribute "MethodOnly" cannot target property (allowed targets: method)
