<?php

declare(strict_types=1);

class C
{
    public string $x {
        get => $this->v ?? 'u';
        set(string $value) { $this->v = $value; }
    }

    private ?string $v = 'a';
}

$c = new C();
try {
    $c->x = 1;
    echo "no set error\n";
} catch (TypeError $e) {
    echo 'set TypeError', "\n";
}
