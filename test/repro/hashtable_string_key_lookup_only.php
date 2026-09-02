<?php
// #36408 probe: lookup-only timing (array pre-built in same run).
$n = (int) ($argv[1] ?? 100000);
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
