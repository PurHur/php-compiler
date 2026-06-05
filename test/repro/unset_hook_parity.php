<?php
// get-only hook — must Error (read-only)
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

// read/write hook — must clear backing
class RW {
    private ?string $v = 'a';
    public string $x { get => $this->v ?? 'u'; set => $this->v = $value; }
}
$h = new RW();
unset($h->x);
echo 'RW isset=', var_export(isset($h->x), true), ' value=', $h->x, "\n";
