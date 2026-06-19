<?php
class C {
    public string $x {
        get => 'g';
        private(set);
    }
}
$c = new C();
echo $c->x, "\n";
