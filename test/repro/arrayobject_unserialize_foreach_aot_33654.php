<?php
// AOT: foreach over unserialize(ArrayObject) uses __spl_ht (#33654).
// Fill NestedJIT (#33636) restores string-key + int-value pairs only.
$ao = unserialize('O:11:"ArrayObject":4:{i:0;i:0;i:1;a:2:{s:1:"a";i:10;s:1:"b";i:20;}i:2;a:0:{}i:3;N;}');
foreach ($ao as $k => $v) {
    echo $k, '=', var_export($v, true), ';';
}
echo "\n";
$it = unserialize('O:13:"ArrayIterator":4:{i:0;i:0;i:1;a:1:{s:1:"y";i:9;}i:2;a:0:{}i:3;N;}');
foreach ($it as $k => $v) {
    echo $k, '=', var_export($v, true), ';';
}
echo "\n";
