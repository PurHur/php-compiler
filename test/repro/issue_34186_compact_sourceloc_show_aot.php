<?php
trait TraitX { public function tm() {} }
class T { use TraitX; public function f() {} }
class U extends T {}
$rc = new ReflectionClass(U::class);
function show($label, $r) {
  if ($r === null) echo "$label => NULL\n";
  elseif (is_bool($r)) echo "$label => bool ".($r?"T":"F")."\n";
  elseif (is_array($r)) echo "$label => array n=".count($r)."\n";
  elseif (is_object($r)) echo "$label => obj ".get_class($r)."\n";
  elseif (is_string($r)) echo "$label => str len=".strlen($r)."\n";
  elseif (is_int($r)) echo "$label => int $r\n";
  else echo "$label => ".gettype($r)."\n";
}
show("a", $rc->getStartLine());
show("b", $rc->getStartLine());
show("c", $rc->getStartLine());
echo "done\n";
