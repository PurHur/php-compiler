--TEST--
Instance property hooks — string post/pre inc/dec via get+set hooks (#6452, zend_property_hooks.c)
--FILE--
<?php
class S {
    private string $v = 'a';
    public string $label {
        get => $this->v;
        set => $this->v = $value;
    }
}
$s = new S();
echo $s->label++, "\n";
echo $s->label, "\n";
++$s->label;
echo $s->label, "\n";
--EXPECT--
a
b
c
