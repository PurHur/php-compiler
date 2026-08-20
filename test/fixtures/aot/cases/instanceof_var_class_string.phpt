--TEST--
AOT: instanceof with runtime string class name (#32766, #32775)
--FILE--
<?php
class A {}
class B {}
$o = new A();
var_dump($o instanceof A);
$n = 'A';
var_dump($o instanceof $n);
$m = 'B';
var_dump($o instanceof $m);
$other = new A();
var_dump($o instanceof $other);
function c($obj, $name) { var_dump($obj instanceof $name); }
c($o, 'A');
c($o, 'stdClass');
function name() { return 'A'; }
$rn = name();
var_dump($o instanceof $rn);
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
bool(true)
--EXPECT_EXIT--
0
