--TEST--
Language: property hook with default initializer (#11594, Zend/zend_compile.c PHP 8.4)
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
