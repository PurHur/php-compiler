<?php
declare(strict_types=1);

class Box {
    public string $label {
        get => strtoupper($this->label);
    }
}

try {
    unset((new Box())->label);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
