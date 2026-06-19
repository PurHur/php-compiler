<?php
declare(strict_types=1);

class C implements Stringable {
    public function __toString(): string { return 'hello'; }
}

try {
    echo strlen(new C()), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
