<?php
// AOT: unserialize(ArrayObject) restores string values in bag (#33663).
$ao = unserialize('O:11:"ArrayObject":4:{i:0;i:0;i:1;a:2:{s:1:"b";s:1:"x";s:1:"c";i:7;}i:2;a:0:{}i:3;N;}');
echo count($ao), ':', json_encode($ao), "\n";
foreach ($ao as $k => $v) {
    echo $k, '=', var_export($v, true), ';';
}
echo "\n";
