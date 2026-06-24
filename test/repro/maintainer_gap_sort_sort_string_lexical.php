<?php
declare(strict_types=1);

$a = [3, 20, 5];
sort($a, SORT_STRING);
echo 'sort=' . json_encode($a) . "\n";

$b = [3, 20, 5];
rsort($b, SORT_STRING);
echo 'rsort=' . json_encode($b) . "\n";
