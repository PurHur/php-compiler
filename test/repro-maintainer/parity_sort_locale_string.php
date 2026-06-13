<?php

declare(strict_types=1);

setlocale(LC_COLLATE, 'de_DE.UTF-8');
$a = ['z', 'ä', 'b'];
sort($a, SORT_LOCALE_STRING);
echo implode(',', $a), "\n";
