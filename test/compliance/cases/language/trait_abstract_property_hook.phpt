--TEST--
Trait abstract property hooks — using class must implement get/set (#7316, Zend/zend_compile.c)
--FILE--
<?php
trait T {
    public string $x { get; set; }
}
class C {
    use T;
}
$c = new C();
$c->x = 'a';
echo $c->x, "\n";
--EXPECT_EXIT--
255
