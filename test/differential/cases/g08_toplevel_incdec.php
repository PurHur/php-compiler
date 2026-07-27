<?php
// #23840: ++/-- silently stopped taking effect at SCRIPT SCOPE. `$n = 5; $n--;` kept 5. Regression
// from #23781, which elided the ++/-- resource guard for values that provably cannot be resources
// — correct in a function body, but script-scope operands (functionStaticGlobal) reach storage
// through a different path, and eliding the guard there dropped the write entirely.
//
// Every pre-existing ++/-- differential case declares its variables INSIDE a function, so nothing
// covered this and the regression shipped. This case is deliberately top-level.
//
// Scope note, so the narrowness is on the record rather than quietly convenient: this case covers
// plain script-scope ++/-- statements only. A `for` loop placed AFTER them still yields the wrong
// accumulator under AOT, and so does a second script-scope variable — both filed as #23842. Those
// failures are NOT from #23781: they reproduce with the resource guard force-disabled, and
// force-disabling it makes even the first `$n--` wrong, so the guard is not the cause either.
// Including them here would leave this case permanently red and useless as a gate; excluding them
// keeps it gating the #23840 regression on BOTH backends. #23842 carries the reproducers.
$n = 5;
$n--;
echo $n, "\n";

$n--;
$n--;
echo $n, "\n";

--$n;
echo $n, "\n";

$n++;
echo $n, "\n";

$n++;
$n++;
$n--;
echo $n, "\n";
