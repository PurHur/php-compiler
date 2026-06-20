<?php

// Maintainer repro for #9761 / #6435 — foreach by-ref on property hooks (zend_property_hooks.c).

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

class GetOnly {
    private int $_n = 0;
    public int $x {
        get => $this->_n;
    }
}
$g = new GetOnly();
try {
    foreach ([1] as &$g->x) {
        echo "loop\n";
    }
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
