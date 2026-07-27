<?php
// #23842: script-scope ++/-- and assign-op must be visible to echo under AOT.
$a = 1;
$a++;
$b = 2;
$b++;
echo $b, "\n";
$acc = 0;
for ($i = 0; $i < 5; ++$i) {
    ++$acc;
}
echo $acc, "\n";
