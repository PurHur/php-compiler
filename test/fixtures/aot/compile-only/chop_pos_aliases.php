<?php
declare(strict_types=1);
// AOT compile-only: chop() alias lowers via string_rtrim JIT path (#4965).
echo chop('  ab  '), "\n";
echo chop("xy\t\n"), "\n";
var_dump(function_exists('chop'), function_exists('pos'));
$a = [10 => 'x', 20 => 'y'];
reset($a);
echo pos($a), "\n";
