--TEST--
#[\Override] with parent class declared later in file (issue #9721)
--FILE--
<?php
class B extends A {
    #[\Override]
    public function f(): void {}
}
class A {
    public function f(): void {}
}
echo "ok\n";
--EXPECT--
ok
