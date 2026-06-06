--TEST--
unset() on property hooks invokes declared unset hook (issue #6502, zend_property_hooks.c)
--FILE--
<?php
class Box {
    public string $label {
        get => $this->label ?? 'default';
        set => $this->label = $value;
        unset => $this->label = 'cleared';
    }
}
$b = new Box;
$b->label = 'hi';
unset($b->label);
echo $b->label, "\n";
--EXPECT--
cleared
