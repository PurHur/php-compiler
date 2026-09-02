<?php
// #36406 / re-#36244: strlen() must read runtime length after `.=` / loop concat, not stale init compileTimeString.
$s = '';
for ($i = 0; $i < 5; $i++) {
    $s .= 'x';
}
echo $s, '|', strlen($s), "\n";

$t = '';
$t .= 'ab';
$t .= 'cd';
echo $t, '|', strlen($t), "\n";

$u = 'a';
for ($i = 0; $i < 5; $i++) {
    $u = $u.'x';
}
echo $u, '|', strlen($u), "\n";
