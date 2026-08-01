<?php
// Issue #26373 — unset() on backed get+set hooked property must Error (Zend 8.4).
class Backed {
    private mixed $s = 1;
    public mixed $x {
        get => $this->s;
        set { $this->s = $value; }
    }
}
$o = new Backed;
try {
    unset($o->x);
    echo "UNSET_OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
