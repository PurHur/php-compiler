--TEST--
Get-only instance property hook rejects inc/dec (#6309, #4821, zend_property_hooks.c)
--FILE--
<?php
class Box {
    private int $n = 0;
    public int $count {
        get => $this->n;
    }
}
$b = new Box();
try {
    $b->count++;
    echo "inc ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
Error
