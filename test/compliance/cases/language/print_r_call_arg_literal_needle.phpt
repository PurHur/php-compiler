--TEST--
print_r(..., true) as call arg keeps distinct literal needle (#24372)
--FILE--
<?php
// Nested print_r/var_export return used as haystack must not remap the following string literal
// onto the same temporary (Zend/zend_vm_def.c call-arg evaluation).
var_export(str_contains(print_r([1, 2, 3], true), 'zzz'));
echo "\n";
$n = 'zzz';
var_export(str_contains(print_r([1, 2, 3], true), $n));
echo "\n";
$s = print_r([1, 2, 3], true);
var_export(str_contains($s, 'zzz'));
echo "\n";
var_export(strpos(print_r([1, 2, 3], true), 'zzz'));
echo "\n";
var_export(str_starts_with(print_r([1, 2, 3], true), 'zzz'));
echo "\n";
var_export(str_ends_with(print_r([1, 2, 3], true), 'zzz'));
echo "\n";
var_export(strstr(print_r([1, 2, 3], true), 'zzz'));
echo "\n";
var_export(str_contains(var_export([1, 2, 3], true), 'zzz'));
echo "\n";
function sc_24372($hay, $needle) {
    echo strlen((string) $hay), '|', strlen((string) $needle), '|', ($hay === $needle) ? '1' : '0', "\n";
}
sc_24372(print_r([1], true), 'zzz');
--EXPECT--
false
false
false
false
false
false
false
false
23|3|0
