--TEST--
Property get/set hooks — VM parity (issue #4205, Zend property hooks)
--FILE--
<?php
class Box {
    public string $label {
        get => strtoupper($this->label);
        set => $value = trim($value);
    }
}
$b = new Box();
$b->label = " hi ";
echo $b->label, "\n";
--EXPECT--
 HI
