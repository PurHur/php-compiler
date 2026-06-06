--TEST--
Language: #[\Override] on trait method — validate at trait use site (#6761)
--FILE--
<?php
trait T {
    #[\Override]
    public function foo(): void {}
}
class A {
    public function foo(): void {}
}
class B extends A {
    use T;
}
echo "ok\n";
--EXPECT--
ok
