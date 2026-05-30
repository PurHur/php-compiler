--TEST--
interface implementation with required method (issue #144)
--FILE--
<?php
interface I {
    public function m(): void;
}
class C implements I {
    public function m(): void {}
}
echo "ok\n";
--EXPECT--
ok
