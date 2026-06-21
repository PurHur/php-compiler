--TEST--
Language: property hook duplicate backing property name — compile fatal (#10393, zend_compile.c)
--FILE--
<?php
class C {
    public int $x {
        get => $this->x;
        set => $this->x = $value;
    }
    public string $y = 'a';
    private int $x = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255
