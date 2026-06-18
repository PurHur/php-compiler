<?php
class C {
    public string $x {
        get => $this->x ?? 'default';
        unset => throw new Exception('unset hook');
    }
}
$c = new C();
$c->x = 'hi';
try {
    unset($c->x);
    echo "no throw\n";
} catch (Exception $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
