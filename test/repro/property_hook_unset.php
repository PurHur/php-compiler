<?php
class C {
    private string $x = 'a';
    public string $x {
        get => $this->x;
        unset { unset($this->x); }
    }
}
$c = new C;
unset($c->x);
if (!property_exists($c, 'x')) {
    echo "FAIL: property missing\n";
    exit(1);
}
if (isset($c->x)) {
    echo "FAIL: still isset after unset\n";
    exit(1);
}
echo "PASS_PROPERTY_HOOK_UNSET\n";
