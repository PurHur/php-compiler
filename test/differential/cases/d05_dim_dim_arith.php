<?php
function f($a,$b,$c){ echo "$a|$b|$c\n"; }
$x=5; $r=['a'=>'A','b'=>'B']; f($r['a'],$r['b'],$x+1);
