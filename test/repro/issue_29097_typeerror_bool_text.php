<?php
// @differential-skip-aot: TypeError message text only; AOT exception string path covered by VM/JIT
function need_array(array $x) {}
foreach ([
  'count_false' => fn() => count(false),
  'count_true' => fn() => count(true),
  'need_array_false' => fn() => need_array(false),
  'count_null' => fn() => count(null),
] as $name => $fn) {
  try {
    $fn();
  } catch (Throwable $e) {
    echo $name, ':', $e->getMessage(), "\n";
  }
}
