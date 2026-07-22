<?php
/**
 * #22029 — Attribute class declared inside a function: ReflectionAttribute
 * getName()/getArguments()/newInstance() must match Zend (php-src-strict).
 */
function probe() {
    #[Attribute]
    class NestedAttr {
        public function __construct(public int $x = 0) {}
    }
    #[NestedAttr(5)]
    class NestedTarget {}
    $a = (new ReflectionClass(NestedTarget::class))->getAttributes()[0] ?? null;
    if (!$a) {
        return null;
    }

    return [$a->getName(), $a->getArguments(), $a->newInstance()->x];
}
$r = probe();
echo null === $r ? "null\n" : ($r[0] . ' ' . json_encode($r[1]) . ' ' . $r[2] . "\n");
