--TEST--
Language: asymmetric visibility compile — property and promoted param (#10199, PHP 8.4 zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
$c = new C();
echo $c->x, "\n";

class D {
    public function __construct(public private(set) int $x = 1) {}
}
$d = new D();
echo $d->x, "\n";
--EXPECT--
1
1
