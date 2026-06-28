--TEST--
Language: property hook with default initializer must compile-error (#12995, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public string $label = 'default' {
        get => $this->label;
    }
}
$c = new C();
echo $c->label, "\n";
--EXPECT_EXIT--
255
