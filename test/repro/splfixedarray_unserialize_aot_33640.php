<?php
// AOT: unserialize(SplFixedArray) restores __spl_ht elements (#33640).
$s = 'O:13:"SplFixedArray":3:{i:0;i:10;i:1;N;i:2;s:1:"x";}';
$a = unserialize($s);
echo $a->getSize(), "\n";
echo $a[0], "\n";
echo null === $a[1] ? "NULL\n" : (string) $a[1]."\n";
echo $a[2], "\n";
// Roundtrip via construct null pads (fromArray drops nulls under thin AOT independently).
$t = new SplFixedArray(3);
$t[0] = 7;
$t[2] = 'z';
$round = unserialize(serialize($t));
echo $round->getSize(), "\n";
echo $round[0], "\n";
echo null === $round[1] ? "NULL\n" : (string) $round[1]."\n";
echo $round[2], "\n";
$empty = unserialize('O:13:"SplFixedArray":0:{}');
echo $empty->getSize(), "\n";
