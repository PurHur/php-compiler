<?php
/**
 * #19591 — nullsafe ?-> on live typed property after prior null short-circuit.
 */
class B {
    public string $x = 'x';
    public function f(): string {
        return 'ok';
    }
}
class A {
    public ?B $b = null;
}
$a = new A();
echo 'null_method=', ($a->b?->f() ?? 'n'), "\n";
$a->b = new B();
echo 'live_method=', ($a->b?->f() ?? 'n'), "\n";
echo 'live_prop=', ($a->b?->x ?? 'n'), "\n";
echo "DONE\n";
