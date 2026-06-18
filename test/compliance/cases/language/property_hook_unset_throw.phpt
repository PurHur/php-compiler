--TEST--
Language: property unset hook throw must abort unset and propagate (#9666, zend_property_hooks.c)
--FILE--
<?php
class C {
    public string $x {
        get => $this->x ?? 'default';
        unset => throw new Exception('unset hook');
    }
}
$c = new C();
$c->x = 'hi';
try {
    unset($c->x);
    echo "no throw\n";
} catch (Exception $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
echo 'still set=', $c->x, "\n";
--EXPECT--
caught: unset hook
still set=hi
