<?php
// AOT probe #23243 — named array: works; phantom flags: rejected (no Reflection)
$a = ['10', '2'];
natsort(array: $a);
echo implode(',', array_values($a)), "\n";
$b = ['B', 'a'];
natcasesort(array: $b);
echo implode(',', array_values($b)), "\n";
try {
    $c = ['10', '2'];
    natsort($c, flags: 0);
    echo "flags_accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
