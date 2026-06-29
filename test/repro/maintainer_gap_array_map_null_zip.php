<?php

declare(strict_types=1);

$z = array_map(null, [1, 2], [3, 4]);
$expect = [[1, 3], [2, 4]];
$ok = true;
foreach ($expect as $i => $row) {
    if (!isset($z[$i]) || !\is_array($z[$i])) {
        $ok = false;
        break;
    }
    if ($z[$i] !== $row) {
        $ok = false;
        break;
    }
}
if (!$ok) {
    var_export($z);
    exit(1);
}
echo "ok\n";
exit(0);
