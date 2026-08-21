<?php
// AOT: foreach after unserialize(ArrayObject) must not SEGV (#33654)
$s = 'O:11:"ArrayObject":4:{i:0;i:0;i:1;a:2:{i:0;i:10;i:1;s:1:"x";}i:2;a:0:{}i:3;N;}';
$ao = unserialize($s);
foreach ($ao as $k => $v) {
    echo $k, '=', var_export($v, true), ';';
}
echo "\n";
