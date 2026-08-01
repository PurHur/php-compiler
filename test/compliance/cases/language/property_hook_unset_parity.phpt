--TEST--
unset() on property hooks — get-only and read/write both Error without unset hook (issue #6609 / #26373, zend_property_hooks.c)
--FILE--
<?php
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
--EXPECT--
RO: Error: Cannot unset hooked property RO::$x
RW: Error: Cannot unset hooked property RW::$x
