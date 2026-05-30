--TEST--
implements: class implements interface; instanceof on object (VM, #101)
--FILE--
<?php
interface I {
    public function m(): void;
}
class C implements I {
    public function m(): void {
        echo "ok";
    }
}
$c = new C();
echo ($c instanceof I) ? '1' : '0';
echo "\n";
$c->m();
echo "\n";
--EXPECT--
1
ok
