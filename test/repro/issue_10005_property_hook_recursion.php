<?php
class C {
    public string $x {
        get { return $this->x; }
    }
}
$c = new C();
try {
    echo "before\n";
    $v = $c->x;
    echo "val={$v}\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "after\n";
