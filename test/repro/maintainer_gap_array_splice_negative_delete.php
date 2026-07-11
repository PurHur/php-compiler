<?php

$a = [0, 1, 2, 3];
$removed = array_splice($a, -1, 1);
echo 'case1 a=' . json_encode($a) . ' removed=' . json_encode($removed) . "\n";
if ($a !== [0, 1, 2] || $removed !== [3]) {
    echo "FAIL case1\n";
    exit(1);
}

$b = [0, 1, 2, 3, 4];
$removed2 = array_splice($b, -2, 2);
echo 'case2 b=' . json_encode($b) . ' removed=' . json_encode($removed2) . "\n";
if ($b !== [0, 1, 2] || $removed2 !== [3, 4]) {
    echo "FAIL case2\n";
    exit(1);
}

echo "ok\n";
