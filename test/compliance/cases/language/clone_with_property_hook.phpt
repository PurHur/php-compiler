--TEST--
Language: clone with invokes property set hooks (#7251, zend_property_hooks.c)
--FILE--
<?php
class C {
    public int $stored {
        get => $this->stored;
        set (int $v) { $this->stored = $v * 10; }
    }
}
$c = new C();
$c->stored = 1;
$d = clone $c with { stored: 2 };
echo $d->stored, "\n";
$x = $c->stored = 3;
var_export([$x, $c->stored]);
--EXPECT--
20
array (
  0 => 3,
  1 => 30,
)
