<?php
function f($a,$b,$c){ echo "$a $b $c\n"; }
$r=['a'=>'A','b'=>'B','c'=>'C']; f($r['a'],$r['b'],$r['c']);
