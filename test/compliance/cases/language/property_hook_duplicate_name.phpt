--TEST--
Language: property hook virtual get + detached same-name field — compile fatal (#10393, zend_compile.c)
--FILE--
<?php
class C {
    public int $x {
        get => 1;
    }
    public string $y = 'a';
    private int $x = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255
