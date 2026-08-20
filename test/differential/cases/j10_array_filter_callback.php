<?php
$a = [1, 2, 3, 4];
echo implode(',', array_filter($a, fn($x) => $x % 2 === 0)), "\n";
