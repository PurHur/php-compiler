<?php

$out = preg_replace('/a/', null, 'abc');
echo $out === 'bc' ? "ok\n" : "fail out={$out}\n";

$count = 0;
$out2 = preg_replace('/a/', null, 'abc', -1, $count);
echo ($out2 === 'bc' && 1 === $count) ? "count_ok\n" : "count_fail out={$out2} count={$count}\n";
