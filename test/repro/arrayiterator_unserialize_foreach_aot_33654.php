<?php
// AOT: foreach after unserialize(ArrayIterator) must not SEGV (#33654)
$it = unserialize('O:13:"ArrayIterator":4:{i:0;i:0;i:1;a:2:{i:0;i:10;i:1;s:1:"x";}i:2;a:0:{}i:3;N;}');
foreach ($it as $k => $v) {
    echo $k, '=', var_export($v, true), ';';
}
echo "\n";
