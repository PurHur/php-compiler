<?php
trait T {
    public string $label = 'from-trait' {
        get => $this->label;
    }
}
class C {
    use T;
}
$c = new C();
echo var_export($c->label, true), "\n";
