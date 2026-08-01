<?php
// Issue #6609 / #26373 — Zend parity for unset() on property hooks (zend_property_hooks.c).
// get-only and read/write hooks without unset — must Error (Zend 8.4+).
class RO {
    public string $x { get => $this->v; }
    private string $v = 'a';
}
$h = new RO();
try {
    unset($h->x);
    echo "RO: done\n";
} catch (Throwable $e) {
    echo 'RO: ', get_class($e), ': ', $e->getMessage(), "\n";
}

// read/write hook — also Error (prior #6609 cleared backing; php-src rejects)
class RW {
    private ?string $v = 'a';
    public string $x { get => $this->v ?? 'u'; set => $this->v = $value; }
}
$h = new RW();
try {
    unset($h->x);
    echo "RW: done\n";
} catch (Throwable $e) {
    echo 'RW: ', get_class($e), ': ', $e->getMessage(), "\n";
}
