--TEST--
Language: private $this->method() early-binds declaring scope (zend_object_handlers.c, #22928)
--FILE--
<?php
class A {
    private function f(): string {
        return 'A';
    }

    public function g(): string {
        return $this->f();
    }

    public function viaOther(A $o): string {
        return $o->f();
    }
}

class B extends A {
    private function f(): string {
        return 'B';
    }
}

echo (new B())->g(), "\n";
echo (new A())->g(), "\n";
echo (new A())->viaOther(new B()), "\n";

// Child still cannot call parent private (#4864)
class Base {
    private function secret(): string {
        return 'base';
    }
}

class Child extends Base {
    public function callInst(): void {
        try {
            echo $this->secret(), "\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
    }
}

(new Child())->callInst();

// Unrelated object with private same name: do not rebind (#22928)
class Scope {
    private function f(): string {
        return 'S';
    }

    public function call($o): string {
        return $o->f();
    }
}

class Other {
    private function f(): string {
        return 'O';
    }
}

try {
    echo (new Scope())->call(new Other()), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
A
A
A
Error: Call to private method Base::secret() from scope Child
Error: Call to private method Other::f() from scope Scope
