<?php
trait T {
    private const X = 99;
    public function get(): int {
        return self::X;
    }
}
class C { use T; }
$c = new C();
var_export($c->get());
echo "\n";
