<?php
function g($n){ for($i=0;$i<$n;$i++) yield $i; }
foreach(g(3) as $v) echo $v;
