<?php
$i = new DateInterval('P2Y3M4DT5H6M7S');
foreach (['y', 'm', 'd', 'h', 'i', 's', 'invert'] as $p) {
    $b = $i->$p;
    unset($i->$p);
    $a = $i->$p;
    if ($b !== $a) {
        echo "$p before=", var_export($b, true), ' after=', var_export($a, true), "\n";
    }
}
$i->d = 9;
echo 'write=', $i->d, "\n";
echo "done\n";
