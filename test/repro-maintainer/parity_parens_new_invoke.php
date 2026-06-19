<?php
declare(strict_types=1);

try {
    (new class {
        public function __construct(public int $x) {}
    })(3);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
