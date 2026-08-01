--TEST--
Language: unset() on backed get+set hooked property throws Error (#26373, zend_property_hooks.c)
--FILE--
<?php
class Backed {
    private mixed $s = 1;
    public mixed $x {
        get => $this->s;
        set { $this->s = $value; }
    }
}
$o = new Backed;
try {
    unset($o->x);
    echo "UNSET_OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

class VirtualBoth {
    public mixed $x {
        get => 1;
        set {}
    }
}
$v = new VirtualBoth;
try {
    unset($v->x);
    echo "VIRTUAL_OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Error:Cannot unset hooked property Backed::$x
Error:Cannot unset hooked property VirtualBoth::$x
