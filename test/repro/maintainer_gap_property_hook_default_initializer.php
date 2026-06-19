<?php
class C {
    public string $label = 'default' {
        get => $this->label;
    }
}
$c = new C();
echo var_export($c->label, true), "\n";
