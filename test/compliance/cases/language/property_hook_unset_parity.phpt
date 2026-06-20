--TEST--
unset() on property hooks — get-only Error, read/write clears backing (issue #6609, zend_property_hooks.c)
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
unset($h->x);
echo 'RW isset=', var_export(isset($h->x), true), ' value=', $h->x, "\n";
--EXPECT--
RO: Error: Cannot unset hooked property RO::$x
RW isset=NULL value=u
