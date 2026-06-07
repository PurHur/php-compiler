--TEST--
Instance property hooks — arrow set + prefix/postfix inc/dec (#6424, zend_property_hooks.c)
--FILE--
<?php
class H {
    public int $x {
        get => $this->v;
        set => $this->v = $value;
    }
    private int $v = 1;
}
$h = new H();
++$h->x;
echo $h->x, "\n";
$h->x = 1;
echo $h->x++, "\n";
echo $h->x, "\n";
--EXPECT--
2
1
2
