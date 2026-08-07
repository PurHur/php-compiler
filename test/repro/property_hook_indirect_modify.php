<?php
// Repro #28590 — dim/append without &get must Error (php-src-strict).
class C {
    public array $items {
        get => $this->items ?? [];
        set => $this->items = $value;
    }
}
$c = new C();
try {
    $c->items[] = 1;
    echo "WROTE ", var_export($c->items, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
