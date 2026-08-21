<?php
// AOT: foreach after unserialize(SplFixedArray) must not SEGV (#33649)
$s = 'O:13:"SplFixedArray":3:{i:0;i:10;i:1;N;i:2;s:1:"x";}';
$a = unserialize($s);
echo 'size=', $a->getSize(), "\n";
foreach ($a as $i => $v) {
    echo $i, '=', var_export($v, true), "\n";
}
