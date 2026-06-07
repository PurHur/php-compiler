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
