<?php
class C {
    public string $x {
        set => $this->x = strtoupper($value);
    }
}
$c = new C();
$c->x = 'hi';
try {
    echo $c->x, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
