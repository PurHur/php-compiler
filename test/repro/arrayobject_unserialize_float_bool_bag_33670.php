<?php
/**
 * #33670 — AOT unserialize(ArrayObject) must restore float/bool/null bag values.
 */
$payload = 'O:11:"ArrayObject":4:{i:0;i:0;i:1;a:3:{s:1:"f";d:1.5;s:1:"b";b:1;s:1:"n";N;}i:2;a:0:{}i:3;N;}';
$ao = unserialize($payload);
echo count($ao), ':', json_encode($ao);
echo "\n";
foreach ($ao as $k => $v) {
    echo $k, '=', var_export($v, true), ";\n";
}
