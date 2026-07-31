<?php
/**
 * Repro for #25669 — outside-scope private/protected instance must dispatch __call.
 */
class A {
    private function hid() { return "p"; }
    protected function prot() { return "pr"; }
    public function __call($n, $a) {
        echo "CALL_$n\n";
        return "m";
    }
    public function inside() {
        return $this->hid();
    }
}
class B extends A {
    public function fromChild() {
        return $this->prot();
    }
    public function childPriv() {
        return $this->hid();
    }
}
$a = new A();
echo $a->hid(), "\n";
echo $a->prot(), "\n";
echo $a->inside(), "\n";
$b = new B();
echo $b->fromChild(), "\n";
echo $b->childPriv(), "\n";
