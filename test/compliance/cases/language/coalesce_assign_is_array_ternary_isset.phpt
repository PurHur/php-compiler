--TEST--
Language: $bag = $arr[$k] ?? null then is_array ternary + compound isset keeps CV (#24540)
--FILE--
<?php
// Coalesce-for-assign must register the CV so later SSA reads (type-assert ternary / if)
// do not allocate fresh undefined slots (#24540, re-#24282).
$a = ['k' => [['inf' => 'x']]];
$bag = $a['k'] ?? null;
echo 'rows=', is_array($bag) ? count($bag) : 'null', "\n";
if (is_array($bag) && isset($bag[0]['inf'])) {
    echo 'inf0=', var_export($bag[0]['inf'], true), "\n";
} else {
    echo 'fail type=', gettype($bag), "\n";
}

function coalesce_then_isset($x) {
    $bag = $x ?? null;
    echo 'fn_rows=', is_array($bag) ? count($bag) : 'null', "\n";
    if (is_array($bag) && isset($bag[0]['inf'])) {
        echo 'fn_inf0=', var_export($bag[0]['inf'], true), "\n";
    } else {
        echo 'fn_fail type=', gettype($bag), "\n";
    }
}
coalesce_then_isset([['inf' => 'y']]);

// Null-byte key (MultipleIterator::__debugInfo private storage shape).
$dbg = ["\0SplObjectStorage\0storage" => [['inf' => 'z']]];
$bag2 = $dbg["\0SplObjectStorage\0storage"] ?? null;
echo 'nb_rows=', is_array($bag2) ? count($bag2) : 'null', "\n";
if (is_array($bag2) && isset($bag2[0]['inf'])) {
    echo 'nb_inf0=', var_export($bag2[0]['inf'], true), "\n";
} else {
    echo 'nb_fail type=', gettype($bag2), "\n";
}
--EXPECT--
rows=1
inf0='x'
fn_rows=1
fn_inf0='y'
nb_rows=1
nb_inf0='z'
