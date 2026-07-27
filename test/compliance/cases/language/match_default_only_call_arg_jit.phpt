--TEST--
match default-only integer result as call argument JIT (issue #23984)
--FILE--
<?php
$x = 1;
function id($v) {
    return $v;
}
var_export(id(match ($x) {
    default => 99,
}));
echo "\n";
$r = match ($x) {
    default => 99,
};
var_export($r);
echo "\n";
var_export(id(match ($x) {
    0 => 0,
    default => 99,
}));
echo "\n";
var_export(id(match (1) {
    default => 99,
}));
echo "\n";
var_export(id(match ($x) {
    default => 'ab',
}));
echo "\n";
--EXPECT--
99
99
99
99
'ab'
