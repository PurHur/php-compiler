--TEST--
Language: readonly class property default values compile and run (#18074, Zend/zend_compile.c)
--FILE--
<?php
readonly class C {
    public int $x = 1;
    public string $name = 'x';
}
echo (new C())->x, PHP_EOL;
echo (new C())->name, PHP_EOL;
--EXPECT--
1
x
