--TEST--
Language: backed property hook with default initializer compiles (#16861, Zend/zend_compile.c PHP 8.4)
--FILE--
<?php
class C {
    public string $label = 'default' {
        get => $this->label;
    }
}
$c = new C();
echo $c->label, "\n";
--EXPECT--
default
