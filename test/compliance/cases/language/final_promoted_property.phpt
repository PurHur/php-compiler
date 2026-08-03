--TEST--
Language: final promoted ctor property `public final string $x` (#22451, #27123, Zend/zend_language_parser.y)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
class FP {
    public function __construct(public final string $x) {}
}
class FPPriv {
    public function __construct(private final string $y) {}
    public function getY(): string { return $this->y; }
}
class FPProt {
    public function __construct(protected final int $z) {}
    public function getZ(): int { return $this->z; }
}
$o = new FP("a");
echo $o->x, "\n";
try {
    $o->x = "b";
    echo "WROTE\n";
} catch (Error $e) {
    echo "BLOCKED\n";
}
$r = new ReflectionProperty(FP::class, "x");
echo "isFinal=", var_export($r->isFinal(), true), "\n";
echo "isPromoted=", var_export($r->isPromoted(), true), "\n";
$p = new FPPriv("secret");
echo $p->getY(), "\n";
$q = new FPProt(7);
echo $q->getZ(), "\n";
--EXPECT--
a
WROTE
isFinal=true
isPromoted=true
secret
7
