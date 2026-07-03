--TEST--
Language: bare private(set) with implicit public read — compiles (#15694, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);
class C {
    private(set) string $p = 'x';
}
echo (new C())->p, "\n";
--EXPECT--
x
