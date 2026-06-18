<?php
class B extends A {
    #[\Override]
    public function f(): void {}
}
class A {
    public function f(): void {}
}
echo "ok\n";
