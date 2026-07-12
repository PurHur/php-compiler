--TEST--
declare(ticks=N) nested scope restores previous interval (#3343)
--FILE--
<?php
declare(ticks=1);
$outer = 0;
$inner = 0;
register_tick_function(function () use (&$outer): void {
    ++$outer;
});
{
    declare(ticks=1);
    register_tick_function(function () use (&$inner): void {
        ++$inner;
    });
    $x = 1;
}
$y = 2;
echo $outer > 0 ? 'outer_ok' : 'outer_fail', "\n";
echo $inner > 0 ? 'inner_ok' : 'inner_fail', "\n";
--EXPECT--
outer_ok
inner_ok
