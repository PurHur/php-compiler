<?php
// Differential: array literal dim-assign + array_shift left-to-right (#23979)
$a = [1, 2, 3];
echo json_encode([$a[0] = 99, array_shift($a), $a]), "\n";
