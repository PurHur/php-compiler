<?php
// AOT: unserialize(ArrayObject) restores float/bool bag values (#33670).
$ao = unserialize('O:11:"ArrayObject":4:{i:0;i:0;i:1;a:2:{s:1:"f";d:1.5;s:1:"b";b:1;}i:2;a:0:{}i:3;N;}');
echo count($ao), ':', json_encode($ao), "\n";
foreach ($ao as $k => $v) {
    echo $k, '=', var_export($v, true), ';';
}
echo "\n";
$ao2 = unserialize('O:11:"ArrayObject":4:{i:0;i:0;i:1;a:1:{s:1:"n";N;}i:2;a:0:{}i:3;N;}');
echo count($ao2), ':', json_encode($ao2), "\n";
