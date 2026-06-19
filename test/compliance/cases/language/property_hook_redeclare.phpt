--TEST--
Language: hooked property + same-name backing field — compile fatal (#9805, zend_compile.c)
--FILE--
<?php
class C {
    public int $x {
        get => $this->x;
        set => $this->x = $value;
    }
    private int $x = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255
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
