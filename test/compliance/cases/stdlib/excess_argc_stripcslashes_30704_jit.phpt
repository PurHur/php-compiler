--TEST--
stdlib: stripcslashes() ArgumentCountError wording JIT (#30704)
--FILE--
<?php
foreach ([
    'hi' => static fn () => stripcslashes('a', 'x'),
    'lo' => static fn () => stripcslashes(),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok=', ('A' === stripcslashes('\\x41')) ? '1' : '0', "\n";
--EXPECT--
hi ArgumentCountError: stripcslashes() expects exactly 1 argument, 2 given
lo ArgumentCountError: stripcslashes() expects exactly 1 argument, 0 given
ok=1
