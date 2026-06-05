<?php
trait T {
    public string $x {
        get => $this->__x;
        set(string $v) { $this->__x = $v; }
    }
    private string $__x = '';
}
class C { use T; }
$c = new C();
$c->x = 'ok';
echo $c->x, "\n";
