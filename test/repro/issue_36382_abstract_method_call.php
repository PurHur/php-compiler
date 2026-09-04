<?php
abstract class A { abstract public function foo(): string; }
class C extends A { public function foo(): string { return "ok"; } }
class Holder {
    private A $a;
    public function __construct(A $a) { $this->a = $a; }
    public function run(): string { return $this->a->foo(); }
}
echo (new Holder(new C()))->run();
