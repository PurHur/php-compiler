--TEST--
register_tick_function() and declare(ticks=N) dispatch (#3343)
--FILE--
<?php
declare(ticks=1);
$log = [];
register_tick_function(function () use (&$log): void {
    $log[] = 'tick';
});
$a = 1;
$b = 2;
echo count($log) >= 2 ? 'ticks_ok' : 'ticks_fail', "\n";
--EXPECT--
ticks_ok
