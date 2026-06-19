--TEST--
Property hook with inline default initializer — class and trait (#9945, Zend/zend_compile.c)
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
class U {
    use T;
}
echo (new C())->label, "\n";
echo (new U())->label, "\n";
$c = new C();
$c->label = 'changed';
echo $c->label, "\n";
--EXPECT--
default
from-trait
changed
