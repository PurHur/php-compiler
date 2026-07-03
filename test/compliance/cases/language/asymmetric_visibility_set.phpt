--TEST--
PHP 8.4 asymmetric visibility: public private(set) metadata preserved (#6377)
--FILE--
<?php
class Demo {
    public private(set) string $name = 'a';
}
$d = new Demo();
echo $d->name, "\n";
$d->name = 'b';
--EXPECT--
a
--EXPECT_EXIT--
255
