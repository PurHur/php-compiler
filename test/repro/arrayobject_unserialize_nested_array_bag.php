<?php
// AOT: unserialize(ArrayObject) restores nested array bag values (#33681).
$ao = unserialize('O:11:"ArrayObject":4:{i:0;i:0;i:1;a:1:{s:1:"a";a:2:{i:0;i:1;i:1;i:2;}}i:2;a:0:{}i:3;N;}');
echo count($ao), ':', json_encode($ao['a']), "\n";
