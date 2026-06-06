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
        try {
            echo parent::secret();
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage();
        }
    }
}
(new B())->go();
--EXPECT--
Error: Call to private method A::secret() from scope B
