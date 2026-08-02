<?php
// #26888: AOT preg_match must return 1 and fill $matches (re-#24115 silent wrong-0).
$m = [];
$r = preg_match('/(a)(b)/', 'ab', $m);
echo $r, '|', ($m[1] ?? ''), ($m[2] ?? ''), "\n";
