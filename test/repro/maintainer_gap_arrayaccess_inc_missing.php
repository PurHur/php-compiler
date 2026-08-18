<?php
/** Issue #32015 — $obj[$missing]++ on value-returning ArrayAccess must not write. */
class A implements ArrayAccess
{
    private array $d = [];

    public function offsetExists(mixed $k): bool
    {
        return array_key_exists($k, $this->d);
    }

    public function offsetGet(mixed $k): mixed
    {
        echo "get:$k\n";
        return $this->d[$k] ?? null;
    }

    public function offsetSet(mixed $k, mixed $v): void
    {
        echo "set:$k=$v\n";
        $this->d[$k] = $v;
    }

    public function offsetUnset(mixed $k): void
    {
        unset($this->d[$k]);
    }
}

$a = new A();
$a['k']++;
echo 'isset=', var_export(isset($a['k']), true), "\n";
try {
    echo 'read=', var_export($a['k'], true), "\n";
} catch (Throwable $e) {
    echo 'read_err:', $e->getMessage(), "\n";
}
