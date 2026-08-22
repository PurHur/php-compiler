<?php
$a = [3, 1, 2];
usort($a, function ($p, $q) {
    return $p <=> $q;
});
echo $a[0], '|', $a[1], '|', $a[2];
