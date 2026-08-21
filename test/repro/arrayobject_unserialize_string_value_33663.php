<?php
// AOT: unserialize(ArrayObject) restores string values in __spl_ht (#33663).
$s = 'O:11:"ArrayObject":4:{i:0;i:0;i:1;a:1:{s:1:"b";s:1:"x";}i:2;a:0:{}i:3;N;}';
$ao = unserialize($s);
echo $ao->count(), ':', json_encode($ao), "\n";
foreach ($ao as $k => $v) {
    echo $k, '=', $v, "\n";
}
$mixed = unserialize('O:11:"ArrayObject":4:{i:0;i:0;i:1;a:2:{s:1:"a";i:1;s:1:"b";s:1:"x";}i:2;a:0:{}i:3;N;}');
echo $mixed->count(), ':', json_encode($mixed), "\n";
$it = unserialize('O:13:"ArrayIterator":4:{i:0;i:0;i:1;a:1:{s:1:"y";s:3:"abc";}i:2;a:0:{}i:3;N;}');
echo $it->count(), ':', json_encode($it), "\n";
