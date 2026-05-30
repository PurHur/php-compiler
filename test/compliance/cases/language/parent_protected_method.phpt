--TEST--
parent::protectedMethod() from subclass (issue #3453)
--FILE--
<?php
class A {
    protected function secret(): string {
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
