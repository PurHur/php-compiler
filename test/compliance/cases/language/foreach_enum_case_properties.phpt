--TEST--
Language: foreach(enum case) yields name (+ value if backed) (#23433, Zend/zend_enum.c)
--FILE--
<?php
enum E { case A; }
$ks = [];
foreach (E::A as $k => $v) { $ks[] = "$k=" . var_export($v, true); }
echo implode(",", $ks), "\n";
enum S: string { case A = "x"; }
$ks = [];
foreach (S::A as $k => $v) { $ks[] = "$k=" . var_export($v, true); }
echo implode(",", $ks), "\n";
enum I: int { case A = 7; }
$ks = [];
foreach (I::A as $k => $v) { $ks[] = "$k=" . var_export($v, true); }
echo implode(",", $ks), "\n";
--EXPECT--
name='A'
name='A',value='x'
name='A',value=7
