--TEST--
unset() on property hooks resets backing; isset false without fatal (issue #5191, zend_property_hooks.c)
--FILE--
<?php
class C {
    public string $p {
        get => $this->backing;
        set (string $value) { $this->backing = $value; }
    }
    private string $backing = '';
}
$c = new C();
$c->p = 'a';
unset($c->p);
var_export(isset($c->p));
echo "\n";
$c->p = 'b';
echo $c->p, "\n";
--EXPECT--
false
b
