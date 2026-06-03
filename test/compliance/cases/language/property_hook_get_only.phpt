--TEST--
Get-only virtual property hook rejects writes (issue #4687, Zend zend_property_hooks.c)
--FILE--
<?php
class Box {
    private string $label = 'ok';
    public string $name {
        get => $this->label;
    }
}
$b = new Box();
echo $b->name, "\n";
try {
    $b->name = 'bad';
    echo "assigned\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
ok
Error
