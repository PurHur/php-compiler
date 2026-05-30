--TEST--
protected property readable from subclass method
--FILE--
<?php
class B {
    protected string $p = 'ok';
}

class C extends B {
    public function read(): string {
        return $this->p;
    }
}

echo (new C())->read();
--EXPECT--
ok
