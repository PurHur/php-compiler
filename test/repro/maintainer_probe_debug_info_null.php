<?php
declare(strict_types=1);

class C {
    public function __debugInfo(): ?array {
        return null;
    }
}

var_dump(new C());
