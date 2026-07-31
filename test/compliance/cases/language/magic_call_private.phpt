--TEST--
language: inaccessible private/protected instance → __call (issue #25669, re-#146)
--FILE--
<?php
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
--EXPECT--
CALL_hid
m
CALL_prot
m
p
pr
CALL_hid
m
