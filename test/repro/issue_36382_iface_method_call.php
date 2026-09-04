<?php
interface I { public function foo(): string; }
class C implements I { public function foo(): string { return "ok"; } }
class Holder {
    private I $i;
    public function __construct(I $i) { $this->i = $i; }
    public function run(): string { return $this->i->foo(); }
}
echo (new Holder(new C()))->run();
