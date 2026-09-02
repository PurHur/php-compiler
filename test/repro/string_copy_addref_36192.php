<?php

$s = str_repeat('x', 1 << 20);
$t = 0;
for ($i = 0; $i < 20000; $i++) {
    $u = $s;
    $t += strlen($u);
}
echo $t, "\n";
