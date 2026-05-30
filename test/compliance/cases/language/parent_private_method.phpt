--TEST--
parent::privateMethod() from subclass (issue #3453)
--FILE--
<?php
class A {
    private function secret(): string {
        return 'ok';
    }
}
class B extends A {
    public function go(): void {
        echo parent::secret();
    }
}
(new B())->go();
--EXPECT--
ok
