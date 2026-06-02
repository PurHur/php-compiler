<?php
class Box {
    public string $label {
        get => strtoupper($this->label);
        set (string $v) { $this->label = $v; }
    }
    public function __construct() { $this->label = 'hi'; }
}
$o = new Box();
var_export(isset($o->label));
echo "\n";
var_export(empty($o->label));
echo "\n";
