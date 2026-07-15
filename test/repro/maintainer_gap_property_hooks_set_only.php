<?php
// Issue #19163 — set-only hook read returns backing after write (Zend 8.4).
final class Counter {
    public int $x {
        set {
            $this->x = max(0, $value);
        }
    }
}

$c = new Counter();
$c->x = -1;
echo $c->x, "\n";
