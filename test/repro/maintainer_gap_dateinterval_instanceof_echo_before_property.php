<?php
// Issue #11867 — DateInterval backing lost after instanceof assign + echo.
$di = DateInterval::createFromDateString('1 day');
$is = $di instanceof DateInterval;
echo $is ? "ok\n" : "no\n";
echo $di->d, "\n";
