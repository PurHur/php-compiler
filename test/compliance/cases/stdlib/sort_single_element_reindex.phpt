--TEST--
stdlib sort/rsort/usort/shuffle reindex single-element non-list keys (#25385)
--FILE--
<?php
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
--EXPECT--
sort-str keys=[0] list=1
sort-int keys=[0] list=1
sort-zero keys=[0] list=1
sort-multi keys=[0,1] list=1
rsort keys=[0] list=1
usort keys=[0] list=1
shuffle keys=[0] list=1
asort keys=["k"] list=0
natsort keys=["k"] list=0
