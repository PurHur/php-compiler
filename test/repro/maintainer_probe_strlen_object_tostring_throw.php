<?php
declare(strict_types=1);

class C {
    public function __toString(): string { throw new Exception('nope'); }
}

try {
    echo strlen(new C()), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
