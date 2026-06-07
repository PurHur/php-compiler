<?php
class N {
    public ?int $x {
        get => $this->v;
        set => $this->v = $value;
    }
    private ?int $v = null;
}
$n = new N();
$n->x ??= 5;
echo $n->x, "\n";
