--TEST--
stdlib JIT: getdate/strtotime ArgumentCountError wording (#30714)
--FILE--
<?php
foreach ([
    'getdate' => static fn () => getdate(1, 2),
    'strtotime_hi' => static fn () => strtotime('now', null, 1),
    'strtotime_lo' => static fn () => strtotime(),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$g = getdate(0);
echo 'ok_getdate=', isset($g['year']) ? '1' : '0', "\n";
$t = strtotime('1970-01-01 00:00:00 UTC');
echo 'ok_strtotime=', (false !== $t) ? '1' : '0', "\n";
--EXPECT--
getdate ArgumentCountError: getdate() expects at most 1 argument, 2 given
strtotime_hi ArgumentCountError: strtotime() expects at most 2 arguments, 3 given
strtotime_lo ArgumentCountError: strtotime() expects at least 1 argument, 0 given
ok_getdate=1
ok_strtotime=1
