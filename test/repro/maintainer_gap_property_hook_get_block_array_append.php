<?php
// Updated for #28590 — without &get, append must Error (was RMW success under #19171).
class C {
    public array $items {
        get {
            return $this->items ?? [];
        }
        set {
            $this->items = $value;
        }
    }
}
$c = new C();
try {
    $c->items[] = 'a';
    echo "WROTE\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
