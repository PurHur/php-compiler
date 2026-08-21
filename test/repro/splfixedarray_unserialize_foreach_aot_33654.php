<?php
// AOT: foreach over unserialize(SplFixedArray) uses __spl_ht (#33649 / #33654).
$a = unserialize('O:13:"SplFixedArray":3:{i:0;i:10;i:1;N;i:2;s:1:"x";}');
echo 'size=', $a->getSize(), "\n";
foreach ($a as $k => $v) {
    echo $k, '=', var_export($v, true), ';';
}
echo "\n";
