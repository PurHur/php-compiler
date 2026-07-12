<?php
class C {
    public string $x = 'g' {
        get {
            return $this->x;
        }
        set {
            $this->x = strtoupper($value);
        }
    }
}
$c = new C();
$c->x = 'b';
echo $c->x, "\n";
