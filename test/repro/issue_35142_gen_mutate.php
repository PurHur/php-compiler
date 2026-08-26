<?php
function g(){ $a=0;$b=1; yield $a; $t=$a+$b; $a=$b; $b=$t; yield $a; }
foreach(g() as $v) echo $v;
