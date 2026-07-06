<?php
class C {
    public string $x {
        final set => strtolower($value);
        get => $this->x ?? '';
    }
}
$c = new C();
$c->x = 'ABC';
echo $c->x;
