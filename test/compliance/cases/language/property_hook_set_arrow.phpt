--TEST--
Property hook set (arrow syntax) invokes set hook on assignment (#6409, zend_property_hooks.c)
--FILE--
<?php
class Box {
    private int $stored = 0;
    public int $value {
        get => $this->stored;
        set => $this->stored = $value * 10;
    }
}
$box = new Box();
$box->value = 3;
echo $box->value, "\n";
--EXPECT--
30
