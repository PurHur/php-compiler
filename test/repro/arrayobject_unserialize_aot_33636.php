<?php
// AOT: unserialize(ArrayObject) restores __spl_ht bag (#33636).
$s = 'O:11:"ArrayObject":4:{i:0;i:0;i:1;a:1:{s:1:"x";i:1;}i:2;a:0:{}i:3;N;}';
$ao = unserialize($s);
echo $ao->count(), "\n";
echo $ao['x'], "\n";
echo json_encode($ao), "\n";
echo serialize(unserialize(serialize(new ArrayObject(['a' => 2, 'b' => 3])))), "\n";
$it = unserialize('O:13:"ArrayIterator":4:{i:0;i:0;i:1;a:1:{s:1:"y";i:9;}i:2;a:0:{}i:3;N;}');
echo $it->count(), "\n";
echo json_encode($it), "\n";
$asProps = unserialize('O:11:"ArrayObject":4:{i:0;i:2;i:1;a:1:{s:1:"z";i:7;}i:2;a:0:{}i:3;N;}');
echo $asProps->getFlags(), "\n";
echo $asProps->count(), "\n";
