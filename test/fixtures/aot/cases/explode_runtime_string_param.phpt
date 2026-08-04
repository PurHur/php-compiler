--TEST--
AOT explode() with runtime string (function param) (#27660 NestedJIT)
--FILE--
<?php
function f(string $value) {
  $p = explode(" ", $value, 2);
  echo $p[0], "|", $p[1], "\n";
}
f("X Y");
--EXPECT--
X|Y
