<?php
/**
 * #25385 — sort/rsort/usort/shuffle reindex single-element non-list keys (php-src php_array_sort).
 *
 * VM: full key/list probes. Thin AOT cannot yet rewrite string-key hashtables in NestedJIT
 * helpers (`array_values`/`shuffle` assoc also fail); packed-list AOT is covered by
 * test/fixtures/aot/cases/sort_single_element_reindex.phpt.
 */
function dump($label, $a) {
    echo $label, ' keys=', json_encode(array_keys($a)), ' list=', (int) array_is_list($a), "\n";
}

$a = ['k' => 'v'];
sort($a);
dump('sort-str', $a);
$a = [5 => 'v'];
sort($a);
dump('sort-int', $a);
$a = [0 => 'v'];
sort($a);
dump('sort-zero', $a);
$a = ['b' => 2, 'a' => 1];
sort($a);
dump('sort-multi', $a);
$a = ['k' => 'v'];
rsort($a);
dump('rsort', $a);
$a = ['k' => 'v'];
usort($a, function ($x, $y) { return 0; });
dump('usort', $a);
$a = ['k' => 'v'];
shuffle($a);
dump('shuffle', $a);
$a = ['k' => 'v'];
asort($a);
dump('asort', $a);
$a = ['k' => 'v'];
natsort($a);
dump('natsort', $a);
