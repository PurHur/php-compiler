<?php
// AOT: int-key bag after unserialize(ArrayObject) (#33654)
$s = 'O:11:"ArrayObject":4:{i:0;i:0;i:1;a:2:{i:0;i:10;i:1;s:1:"x";}i:2;a:0:{}i:3;N;}';
$ao = unserialize($s);
echo 'count=', $ao->count(), "\n";
echo '0=', $ao[0], ' 1=', $ao[1], "\n";
