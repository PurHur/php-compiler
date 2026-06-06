--TEST--
Language: new readonly class compiles and runs (issue #6991, zend_compile.c ZEND_ACC_READONLY_ANON_CLASS)
--FILE--
<?php
declare(strict_types=1);

$o = new readonly class {
    public function __construct(public int $x = 1) {}
};
echo $o->x, "\n";
--EXPECT--
1
