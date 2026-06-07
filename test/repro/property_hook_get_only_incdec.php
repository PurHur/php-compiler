<?php
class Box {
    private int $n = 0;
    public int $count {
        get => $this->n;
    }
}
$b = new Box();
try {
    $b->count++;
    echo "inc ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
