<?php
// #36408 probe: insert-only timing for string-key hash index.
$n = (int) ($argv[1] ?? 100000);
$a = [];
for ($i = 0; $i < $n; $i++) {
    $a['k'.$i] = $i;
}
echo "done\n";
