--TEST--
Bare local as call argument inside function matches Zend (CV fetch / SEND_VAR)
--FILE--
<?php
function show_local() {
  $x = 42;
  echo "echo=", $x, "\n";
  var_dump($x + 0);
  var_dump($x);
}
show_local();

function pass_to_user() {
  $x = 7;
  sink($x);
}
function sink($v) {
  var_dump($v);
}
pass_to_user();

// Top-level and params remain OK
$y = 3;
var_dump($y);
function use_param($p) {
  var_dump($p);
}
use_param(9);
?>
--EXPECT--
echo=42
int(42)
int(42)
int(7)
int(3)
int(9)
