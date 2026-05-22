--TEST--
Language: magic constants __CLASS__, __METHOD__, __FUNCTION__ in methods (JIT, #199)
--FILE--
<?php
class C {
    public function id(): string {
        return __CLASS__ . '::' . __FUNCTION__;
    }
}
echo (new C)->id(), "\n";
--EXPECT--
C::id
