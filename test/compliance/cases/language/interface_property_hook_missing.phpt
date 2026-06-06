--TEST--
Language: interface property hooks — missing implementing property compile error (#6770, #6965, zend_compile.c)
--FILE--
<?php
interface I {
    public int $x { get; set; }
}
class Bad implements I {
    public int $y = 1;
}
new Bad;
--EXPECT_EXIT--
255
