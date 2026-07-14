<?php
/**
 * Issue #18878 — parent:: in trait methods must resolve to composing class parent.
 */
trait T {
    public function g(): string {
        return parent::f();
    }
    public static function gs(): string {
        return parent::fs();
    }
}
class P {
    public function f(): string {
        return 'p';
    }
    public static function fs(): string {
        return 'ps';
    }
}
class C extends P {
    use T;
}

echo 'inst=', (new C)->g(), "\n";
echo 'stat=', C::gs(), "\n";
