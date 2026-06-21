--TEST--
Language: asymmetric visibility compile — property (#10199, PHP 8.4 zend_compile.c)
--FILE--
<?php
class C {
    private(set) int $x = 1;
}
$c = new C();
echo $c->x, "\n";
--EXPECT--
1
