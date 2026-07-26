<?php class S{ static function f($a,$b){ return "$a-$b"; } } $x=1; echo S::f($x+1,$x+2), "\n";
