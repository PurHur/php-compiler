--TEST--
Language: readonly hooked property — compiles (#9835, zend_compile.c)
--FILE--
<?php
class C {
    public readonly int $x {
        get => $this->x;
        set { $this->x = $value; }
    }
}
echo "ok\n";
--EXPECT--
ok
