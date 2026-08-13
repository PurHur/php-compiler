--TEST--
stdlib JIT: is_scalar / is_numeric / is_resource excess argc → ArgumentCountError (#30687)
--FILE--
<?php
foreach ([
    'is_scalar' => static fn () => is_scalar(1, 1),
    'is_numeric' => static fn () => is_numeric('1', 1),
    'is_resource' => static fn () => is_resource(1, 1),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok_scalar=', is_scalar(1) ? '1' : '0', "\n";
echo 'ok_numeric=', is_numeric('1') ? '1' : '0', "\n";
echo 'ok_resource=', is_resource(1) ? '1' : '0', "\n";
--EXPECT--
is_scalar ArgumentCountError: is_scalar() expects exactly 1 argument, 2 given
is_numeric ArgumentCountError: is_numeric() expects exactly 1 argument, 2 given
is_resource ArgumentCountError: is_resource() expects exactly 1 argument, 2 given
ok_scalar=1
ok_numeric=1
ok_resource=0
