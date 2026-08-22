<?php
// AOT: unserialize(ArrayObject) restores nested stdClass bag values (#33686).
$wire = 'O:11:"ArrayObject":4:{i:0;i:0;i:1;a:1:{s:1:"x";O:8:"stdClass":1:{s:1:"a";i:1;}}i:2;a:0:{}i:3;N;}';
$o = unserialize($wire);
echo count($o), ':', json_encode($o), "\n";
