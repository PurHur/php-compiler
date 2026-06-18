--TEST--
Language: readonly class property default values (#9355, Zend/zend_compile.c)
--FILE--
<?php
readonly class R {
    public int $x = 1;
    public string $name = 'x';
}
echo (new R)->x, (new R)->name;
--EXPECT--
1x
