--TEST--
Language: property hook with default initializer — parse error (#10592, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public string $label = 'default' {
        get => $this->label;
    }
}
trait T {
    public string $label = 'from-trait' {
        get => $this->label;
    }
}
echo "compiled\n";
--EXPECT_EXIT--
255
