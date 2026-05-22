--TEST--
private method callable via $this from same class
--FILE--
<?php
class C {
    private function secret(): void {
        echo "ok";
    }

    public function go(): void {
        $this->secret();
    }
}

(new C())->go();
--EXPECT--
ok
