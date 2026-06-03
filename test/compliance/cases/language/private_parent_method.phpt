--TEST--
Language: private parent methods not callable from child scope (zend_execute.c, #4864)
--FILE--
<?php
class Base {
    private function secret(): string {
        return 'base';
    }

    protected function prot(): string {
        return 'prot';
    }
}

class Child extends Base {
    public function callStatic(): void {
        try {
            echo Base::secret(), "\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
    }

    public function callInst(): void {
        try {
            echo $this->secret(), "\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
    }

    public function callProt(): void {
        echo $this->prot(), "\n";
    }
}

$c = new Child();
$c->callStatic();
$c->callInst();
$c->callProt();
--EXPECT--
Error: Call to private method Base::secret() from scope Child
Error: Call to private method Base::secret() from scope Child
prot
