<?php
declare(strict_types=1);

class C {
    public int $x {
        get => $this->x;
    }
}

echo "compiled\n";
