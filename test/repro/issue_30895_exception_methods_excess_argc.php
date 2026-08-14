<?php
// Repro for #30895 — Exception/Error get* excess argc
$e = new Exception('x', 0, new Exception('inner'));
foreach ([
  fn() => $e->getMessage(1),
  fn() => $e->getCode(1),
  fn() => $e->getFile(1),
  fn() => $e->getLine(1),
  fn() => $e->getTrace(1),
  fn() => $e->getTraceAsString(1),
  fn() => $e->getPrevious(1),
  fn() => (new Error('e', 9))->getCode(1),
] as $fn) {
  try {
    $r = $fn();
    echo is_array($r) ? 'array('.count($r).')' : (is_string($r) ? substr($r, 0, 40) : var_export($r, true));
    echo "\n";
  } catch (Throwable $ex) {
    echo get_class($ex), ': ', $ex->getMessage(), "\n";
  }
}
