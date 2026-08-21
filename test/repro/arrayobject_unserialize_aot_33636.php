<?php
/** AOT: unserialize(ArrayObject/ArrayIterator) restores __spl_ht bag (#33636). */
$s = 'O:11:"ArrayObject":4:{i:0;i:0;i:1;a:1:{s:1:"x";i:1;}i:2;a:0:{}i:3;N;}';
$ao = unserialize($s);
echo get_class($ao), "\n";
echo $ao->count(), "\n";
echo json_encode($ao->getArrayCopy()), "\n";
echo $ao->getFlags(), "\n";

$asProps = 'O:11:"ArrayObject":4:{i:0;i:2;i:1;a:1:{s:1:"y";i:9;}i:2;a:0:{}i:3;N;}';
$ao2 = unserialize($asProps);
echo $ao2->getFlags(), "|", json_encode($ao2->getArrayCopy()), "\n";

$ai = 'O:13:"ArrayIterator":4:{i:0;i:0;i:1;a:1:{s:1:"z";i:3;}i:2;a:0:{}i:3;N;}';
$it = unserialize($ai);
echo get_class($it), "|", $it->count(), "\n";

$empty = 'O:11:"ArrayObject":4:{i:0;i:0;i:1;a:0:{}i:2;a:0:{}i:3;N;}';
$ao3 = unserialize($empty);
echo get_class($ao3), "|", $ao3->count(), "\n";
