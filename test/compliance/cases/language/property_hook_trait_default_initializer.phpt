--TEST--
Language: trait property hook with default initializer must compile-error (#12995, Zend/zend_compile.c)
--FILE--
<?php
trait T {
    public string $label = 'from-trait' {
        get => $this->label;
    }
}
class C {
    use T;
}
$c = new C();
echo $c->label, "\n";
--EXPECT_EXIT--
255
