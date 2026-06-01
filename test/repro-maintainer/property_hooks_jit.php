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
