--TEST--
VM: trait use adaptation visibility — f as private (#144, #2483)
--FILE--
<?php
trait T { public function f(): int { return 1; } }
class C {
    use T { f as private; }
    public function call(): int { return $this->f(); }
}
$c = new C();
echo $c->call();
--EXPECT--
1
