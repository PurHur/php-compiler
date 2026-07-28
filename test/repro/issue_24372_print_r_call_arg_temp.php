<?php
/**
 * #24372 — print_r(..., true) / var_export(..., true) as call arg must not remap a following
 * string-literal needle onto the nested EXEC_RETURN (haystack === needle).
 */
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

function sc_24372($hay, $needle): void
{
    echo 'hay_len=', strlen((string) $hay), ' needle_len=', strlen((string) $needle);
    echo ' same=', ($hay === $needle) ? '1' : '0', "\n";
}
sc_24372(print_r([1], true), 'zzz');
