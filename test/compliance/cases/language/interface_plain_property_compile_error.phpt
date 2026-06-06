--TEST--
Language: interface plain property without hooks — compile error (#6902, zend_compile.c)
--FILE--
<?php
interface I {
    public string $name;
}
class C implements I {
    public string $name = 'x';
}
echo (new C())->name, "\n";
--EXPECT_EXIT--
255
