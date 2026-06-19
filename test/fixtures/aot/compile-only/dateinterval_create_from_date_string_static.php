<?php
// AOT compile-only (#9993): DateInterval::createFromDateString() static VM builtin.
$di = DateInterval::createFromDateString('1 day');
echo ($di instanceof DateInterval) ? "ok\n" : "bad\n";
echo $di->format('%d'), "\n";
echo DateInterval::createFromDateString('1 day 2 hours')->d, ':', DateInterval::createFromDateString('1 day 2 hours')->h, "\n";
