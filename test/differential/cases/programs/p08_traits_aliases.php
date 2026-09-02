<?php
// #36221 program: traits with alias/insteadof
trait A {
    public function hello(): string { return 'A'; }
    public function id(): string { return 'idA'; }
}
trait B {
    public function hello(): string { return 'B'; }
    public function id(): string { return 'idB'; }
}
class C {
    use A, B {
        B::hello insteadof A;
        A::id insteadof B;
        A::hello as helloA;
        B::id as idB;
    }
    public function all(): string {
        return $this->hello() . '/' . $this->helloA() . '/' . $this->id() . '/' . $this->idB();
    }
}
$c = new C();
$out = $c->all() . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
