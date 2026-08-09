<?php
class H {
    public array $items {
        get => $this->items;
        set => $this->items = $value;
    }
}
$h = new H;
$h->items = [1, 2, 3];
try {
    foreach ($h->items as &$v) {
        $v *= 10;
    }
    unset($v);
    echo 'mutated=';
    var_export($h->items);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
    echo 'unchanged=';
    var_export($h->items);
    echo "\n";
}
