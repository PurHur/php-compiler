--TEST--
Language: readonly hooked property — compile fatal (#9805, zend_compile.c)
--FILE--
<?php
class C {
    public readonly int $x {
        get => $this->x;
        set { $this->x = $value; }
    }
}
echo "ok\n";
--EXPECT_EXIT--
255
