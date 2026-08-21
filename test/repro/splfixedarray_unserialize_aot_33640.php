<?php

// AOT: unserialize(SplFixedArray) restores __spl_ht (#33640).
$s = 'O:13:"SplFixedArray":3:{i:0;i:10;i:1;N;i:2;s:1:"x";}';
$a = unserialize($s);
echo $a->getSize(), "\n";
echo $a[0], "\n";
echo null === $a[1] ? "NULL\n" : ($a[1]."\n");
echo $a[2], "\n";
