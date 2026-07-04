<?php
declare(strict_types=1);

class C {
    public private(set) string $x = 'hi';

    public function get(): string
    {
        return $this->x;
    }
}

echo (new C())->get(), "\n";
$c = new C();
try {
    $c->x = 'no';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
