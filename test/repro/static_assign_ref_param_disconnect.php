<?php
function f(&$x = null) {
  static $s = 0;
  if ($x !== null) { $s = &$x; }
  return ++$s;
}
$a = 10;
echo f($a), ",", f(), ",", f(), ",", $a, "\n";
