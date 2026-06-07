--TEST--
Language: new expression method call in read context still runs (#6691)
--FILE--
<?php
class C {
    public function m(): string {
        return "ok\n";
    }
}
echo (new C())->m();
--EXPECT--
ok
