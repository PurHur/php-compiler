--TEST--
Language: trait backed property hook with default initializer compiles (#16861, Zend/zend_compile.c PHP 8.4)
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
--EXPECT--
from-trait
