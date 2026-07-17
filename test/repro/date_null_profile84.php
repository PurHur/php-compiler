<?php
// #20227 — idate/strftime/date_parse/mktime/gmmktime null TypeError under PROFILE=8.4
foreach ([
  fn() => @idate(null),
  fn() => @strftime(null),
  fn() => date_parse(null),
  fn() => mktime(null),
  fn() => gmmktime(null),
] as $fn) {
  try { $fn(); echo "COERCE\n"; }
  catch (Throwable $e) { echo get_class($e), "\n"; }
}
