--TEST--
Hoisted call args survive mixed producers and block splits (#23354)
--FILE--
<?php
// Two defects met here. Positional mapping gave an earlier argument the trailing producer's slot,
// and a producer computed before a CFG block split (string-keyed array literal, ternary) was
// stranded: temporaries have no name, so they cannot inherit across the split by name.

function f2($a, $b) { echo "$a|$b\n"; }
function f3($a, $b, $c) { echo "$a|$b|$c\n"; }

$x = 5;
$r = ['k' => 'K'];

// Arithmetic/concat producer before a trailing dim-fetch.
f2($x + 1, $r['k']);
f2('s' . '1', $r['k']);
f3($x + 1, $x + 2, $r['k']);

// Trailing property fetch.
class P { public $u = 'U'; }
$p = new P();
f2($x + 1, $p->u);

// Producer stranded before the split, trailing arithmetic.
f3($x + 1, $r['k'], $x + 3);

// Ternary arm before a dim-fetch.
f2($x ? 'T' : 'F', $r['k']);

// Independent nested dim chains must not collapse onto the intermediate array.
$m = ['a' => ['b' => 'AB'], 'c' => ['d' => 'CD']];
f2($m['a']['b'], $m['c']['d']);

// Comparison producers.
var_dump($x > 3, $x < 3);
--EXPECT--
6|K
s1|K
6|7|K
6|U
6|K|8
T|K
AB|CD
bool(true)
bool(false)
