<?php
class C {
    public readonly int $x {
        get => $this->x;
        set { $this->x = $value; }
    }
    public function __construct() {
        $this->x = 0;
    }
}
$c = new C();
try {
    $c->x = 1;
    echo "no-resume\n";
} catch (Throwable $e) {
    echo 'caught: ', get_class($e), "\n";
}
