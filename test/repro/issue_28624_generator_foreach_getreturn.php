<?php
function gen() { yield 1; yield 2; return 9; }
$g = gen();
foreach ($g as $v) echo $v, ",";
echo "ret=", $g->getReturn(), "\n";
