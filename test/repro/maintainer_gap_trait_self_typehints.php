<?php
/**
 * Maintainer gap: self in trait param/return typehints.
 * Zend: self binds to the using class (C)
 * VM: self stays the trait name (T) → TypeError
 */
error_reporting(E_ALL);

trait TSelfType {
    public function take(self $o): string
    {
        return get_class($o);
    }

    public function me(): self
    {
        return $this;
    }
}

class CSelfType
{
    use TSelfType;
}

$a = new CSelfType();
$b = new CSelfType();

try {
    echo 'param: ' . $a->take($b) . "\n";
} catch (Throwable $e) {
    echo 'param: ' . get_class($e) . ':' . $e->getMessage() . "\n";
}

try {
    echo 'return: ' . get_class($a->me()) . "\n";
} catch (Throwable $e) {
    echo 'return: ' . get_class($e) . ':' . $e->getMessage() . "\n";
}
