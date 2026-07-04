<?php
declare(strict_types=1);

class C {
    public int $x = 1;
}

echo (function (): int {
    return $this->x;
})->bindTo(new C(), null)(), PHP_EOL;

$o = new C();
echo (function (): int {
    return $this->x;
})->bindTo($o, null)(), PHP_EOL;
