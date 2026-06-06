<?php
class Evaled {
    public string $name {
        get => strtoupper($this->name ?? "");
        set => $this->name = strtolower($value);
    }
    private string $name = "x";
}
$o = new Evaled();
$o->name = 'AbC';
echo $o->name, "\n";
