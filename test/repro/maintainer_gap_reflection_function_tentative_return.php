<?php
$rf = new ReflectionFunction('strlen');
var_export(method_exists($rf, 'hasTentativeReturnType'));
echo "\n";
var_export($rf->hasTentativeReturnType());
echo "\n";
var_export($rf->getTentativeReturnType());
echo "\n";
function user_fn(): int { return 1; }
$ru = new ReflectionFunction('user_fn');
var_export($ru->hasTentativeReturnType());
echo "\n";
var_export($ru->getTentativeReturnType());
echo "\n";
// Method tentative API must keep working (DateTime::format has tentative string).
$rm = new ReflectionMethod(DateTime::class, 'format');
var_export($rm->hasTentativeReturnType());
echo "\n";
$t = $rm->getTentativeReturnType();
echo null === $t ? 'null' : (string) $t, "\n";
