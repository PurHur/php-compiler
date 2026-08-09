<?php
class C {
    public string $x {
        get => "hello";
    }
}
$o = new C;
echo $o->x, "\n";
echo ($o->x ?? 'rhs'), "\n";

class D {
    public ?string $y {
        get => null;
    }
}
$d = new D;
var_export($d->y ?? 'rhs');
echo "\n";
