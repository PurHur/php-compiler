--TEST--
Language: implements clause — valid implementation compiles (#3536)
--FILE--
<?php
interface I {
    public function required(): int;
}
class C implements I {
    public function required(): int {
        return 42;
    }
}
echo (new C())->required(), "\n";
--EXPECT--
42
