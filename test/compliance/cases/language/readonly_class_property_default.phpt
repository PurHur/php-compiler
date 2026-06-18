--TEST--
Language: readonly class property default values allowed (#9355, Zend/zend_compile.c)
--FILE--
<?php
readonly class R {
    public int $x = 1;
    public string $name = 'x';
}
echo (new R)->x, "\n";
echo (new R)->name, "\n";
--EXPECT--
1
x
