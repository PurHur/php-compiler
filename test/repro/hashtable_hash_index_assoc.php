<?php
// #36191: build + lookup 1k string keys — hash index should beat O(n) list walk.
$n = (int) ($argv[1] ?? 1000);
$a = [];
for ($i = 0; $i < $n; $i++) {
    $a['k'.$i] = $i;
}
$hits = 0;
for ($i = 0; $i < $n; $i++) {
    if (isset($a['k'.$i])) {
        $hits++;
    }
}
echo $hits, "\n";
