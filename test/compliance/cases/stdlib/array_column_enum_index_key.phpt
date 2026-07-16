--TEST--
stdlib array_column() enum index_key cell — TypeError Illegal offset type (#19742, ext/standard/array.c)
--FILE--
<?php
enum E: string { case A = 'a'; }
$rows = [['k' => E::A, 'v' => 1]];
try {
    array_column($rows, 'v', 'k');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
// Column values that are enum cases remain preserved (not this bug).
$kept = array_column([['k' => 'x', 'v' => E::A]], 'v');
echo $kept[0] instanceof E ? "kept\n" : "lost\n";
--EXPECT--
TypeError:Illegal offset type
kept
