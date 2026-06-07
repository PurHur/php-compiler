--TEST--
Language: #[\Override] on trait alias method — valid (#6786)
--FILE--
<?php
trait T { public function f(): void {} }
class C { use T { f as g; } }
class D extends C {
    #[\Override]
    public function g(): void { echo "ok\n"; }
}
(new D())->g();
?>
--EXPECT--
ok
