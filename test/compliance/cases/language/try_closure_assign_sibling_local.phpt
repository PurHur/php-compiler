--TEST--
Language: try-block assign of closure call result must write target CV, not sibling (#29482, Zend/zend_execute.c)
--FILE--
<?php
function notry() {
  $a = null; $b = null;
  $a = (fn()=>7)();
  echo json_encode(['a'=>$a,'b'=>$b]), "\n";
}
function assign_a() {
  $a = null; $b = null;
  try { $a = (fn()=>7)(); } catch (Throwable $e) { $b = 'ERR'; }
  echo json_encode(['a'=>$a,'b'=>$b]), "\n";
}
function assign_b() {
  $a = null; $b = null;
  try { $b = (fn()=>7)(); } catch (Throwable $e) { $a = 'ERR'; }
  echo json_encode(['a'=>$a,'b'=>$b]), "\n";
}
function assign_b_keep_a() {
  $a = 1; $b = null;
  try { $b = (fn()=>7)(); } catch (Throwable $e) { $a = 'ERR'; }
  echo json_encode(['a'=>$a,'b'=>$b]), "\n";
}
notry();
assign_a();
assign_b();
assign_b_keep_a();
--EXPECT--
{"a":7,"b":null}
{"a":7,"b":null}
{"a":null,"b":7}
{"a":1,"b":7}
