<?php
// #23984 — default-only match integer result lost when passed as call arg
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
