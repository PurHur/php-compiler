--TEST--
Each hoisted call argument keeps its own producer (#23354)
--FILE--
<?php
// php-cfg hoists inline argument expressions into statements before the call. Resolving them from
// "the statement immediately before the call" hands every argument the LAST one's value, so these
// all used to print the trailing argument twice (or three times) with no diagnostic.

function f2($a, $b) { echo "$a|$b\n"; }
function f3($a, $b, $c) { echo "$a|$b|$c\n"; }

// Arithmetic producers.
$x = 10;
f2($x + 1, $x + 2);
f3($x + 1, $x + 2, $x + 3);

// Array dim-fetch producers.
$r = ['a' => 'AAA', 'b' => 'BBB', 'c' => 'CCC'];
f2($r['a'], $r['b']);
f3($r['a'], $r['b'], $r['c']);

// Concat producers.
$s = 's';
f2($s . '1', $s . '2');

// Dim-fetch followed by arithmetic.
f2($r['a'], $x + 1);
f3($r['a'], $r['b'], $x + 1);

// Builtins take the same path as user functions.
$p = ['from' => 'xy', 'to' => 'zw'];
echo str_replace($p['from'], $p['to'], 'xy!'), "\n";
echo max($p['from'], $p['to']), "\n";
echo substr('abcdefgh', $x - 8, $x - 6), "\n";
--EXPECT--
11|12
11|12|13
AAA|BBB
AAA|BBB|CCC
s1|s2
AAA|11
AAA|BBB|11
zw!
zw
cdef
