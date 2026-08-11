<?php
declare(strict_types=1);
error_reporting(E_ALL);
foreach ([
  'debug_backtrace' => fn () => debug_backtrace(null),
  'assert_options' => fn () => assert_options(null),
  'ini_restore' => fn () => ini_restore(null),
  'time_sleep_until' => fn () => time_sleep_until(null),
] as $n => $fn) {
  echo "== $n ==\n";
  try {
    var_export($fn());
    echo "\n";
  } catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
  }
}
