--TEST--
stdlib: stream_context_get_options() ArgumentCountError wording JIT (#30785)
--FILE--
<?php
$c = stream_context_create();
foreach ([
    'hi' => static fn () => stream_context_get_options($c, 1),
    'lo' => static fn () => stream_context_get_options(),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok=', is_array(stream_context_get_options($c)) ? '1' : '0', "\n";
--EXPECT--
hi ArgumentCountError: stream_context_get_options() expects exactly 1 argument, 2 given
lo ArgumentCountError: stream_context_get_options() expects exactly 1 argument, 0 given
ok=1
