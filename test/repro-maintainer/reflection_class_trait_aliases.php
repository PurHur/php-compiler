<?php
/** Issue #6661 — ReflectionClass::getTraitAliases() trait adaptation map. */
trait T {
    public function f(): int
    {
        return 1;
    }
}
class C {
    use T {
        f as g;
    }
}
$rc = new ReflectionClass(C::class);
echo method_exists($rc, 'getTraitAliases') ? "1\n" : "0\n";
var_export($rc->getTraitAliases());
echo "\n";
