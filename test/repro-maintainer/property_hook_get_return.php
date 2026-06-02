<?php
class Box {
    public string $label {
        get { return "X"; }
        set (string $value) { $this->label = $value; }
    }
}
$b = new Box();
$b->label = "hi";
var_dump($b->label);
