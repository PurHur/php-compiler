<?php
class Acc {
    private int $_n = 0;
    public int $total {
        get => $this->_n;
        set => $this->_n = $value * 10;
    }
}
$c = new Acc();
foreach ([1, 2, 3] as &$c->total) {
    $c->total++;
}
echo $c->total, "\n";
