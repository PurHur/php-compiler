<?php
/**
 * #29482 — try-block assign of closure call result must write the target CV.
 * Zend: each row's target local receives 7; sibling stays null (or 1 for last).
 */
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
