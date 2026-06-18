--TEST--
Language: property set hook throw must abort assignment and propagate (#9670, zend_property_hooks.c)
--FILE--
<?php
class C {
    public int $x {
        get => $this->x;
        set { throw new Exception('set hook'); }
    }
    private int $x = 0;
}
$c = new C();
try {
    $c->x = 1;
    echo "no-resume\n";
} catch (Throwable $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
--EXPECT--
caught: set hook
