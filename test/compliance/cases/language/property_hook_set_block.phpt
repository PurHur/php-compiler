--TEST--
Property set hook block body stores via $this->prop raw write (#17330, Zend/zend_property_hooks.c)
--FILE--
<?php
class C {
    public string $x = 'g' {
        get {
            return $this->x;
        }
        set {
            $this->x = strtoupper($value);
        }
    }
}
$c = new C();
$c->x = 'b';
echo $c->x, "\n";

class S {
    public string $label {
        get {
            return $this->label;
        }
        set (string $value) {
            $this->label = strtoupper($value);
        }
    }
}
$s = new S();
$s->label = 'hi';
echo $s->label, "\n";
--EXPECT--
B
HI
