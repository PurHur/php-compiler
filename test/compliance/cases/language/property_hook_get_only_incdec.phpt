--TEST--
Get-only instance property hook rejects inc/dec (#6610, #6309, #4821, zend_property_hooks.c)
--FILE--
<?php
class Box {
    private int $n = 0;
    public int $count {
        get => $this->n;
    }
}
$b = new Box();
$msg = 'Property Box::$count is read-only';
foreach ([
    'post++' => function () use ($b) { $b->count++; },
    'post--' => function () use ($b) { $b->count--; },
    'pre++' => function () use ($b) { ++$b->count; },
    'pre--' => function () use ($b) { --$b->count; },
    '+=' => function () use ($b) { $b->count += 1; },
] as $name => $fn) {
    try {
        $fn();
        echo $name, ": ok\n";
    } catch (Throwable $e) {
        echo $name, ': ', get_class($e), ': ', $e->getMessage() === $msg ? 'read-only' : $e->getMessage(), "\n";
    }
}
--EXPECT--
post++: Error: read-only
post--: Error: read-only
pre++: Error: read-only
pre--: Error: read-only
+=: Error: read-only
