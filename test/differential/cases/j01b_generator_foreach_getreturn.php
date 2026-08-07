<?php
// @differential-repeat: 5  AOT foreach+$g+getReturn was segfault/heap class (#28624)
// Kept Generator local + foreach + getReturn — AOT must preserve resume metadata (#28624).
function gen() { yield 1; yield 2; return 9; }
$g = gen();
foreach ($g as $v) {
    echo $v, ",";
}
echo "ret=", $g->getReturn(), "\n";
