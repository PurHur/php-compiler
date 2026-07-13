<?php

declare(strict_types=1);

$a = [];
$a['gc'] = gc_collect_cycles();
foreach ($a as $k => $v) {
    echo $k . '=' . $v . "\n";
}

$b = [];
$b[gc_collect_cycles()] = 2;
foreach ($b as $k => $v) {
    echo $k . '=' . $v . "\n";
}

echo "array_all=ok array_any=ok gc=" . gc_collect_cycles() . "\n";
