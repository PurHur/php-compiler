<?php
// #21491 — idate/mktime/gmmktime soft-null; strftime/date_parse still TypeError under PROFILE=8.4
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    return true;
});
foreach ([
  'idate' => fn() => idate(null),
  'strftime' => fn() => @strftime(null),
  'date_parse' => fn() => date_parse(null),
  'mktime' => fn() => mktime(null),
  'gmmktime' => fn() => gmmktime(null),
] as $name => $fn) {
  try {
    $r = $fn();
    if ('idate' === $name) {
      echo $name, ' ', var_export($r, true), "\n";
    } elseif ('mktime' === $name || 'gmmktime' === $name) {
      echo $name, ' ', (is_int($r) ? 'int' : gettype($r)), "\n";
    } else {
      echo $name, " COERCE\n";
    }
  } catch (Throwable $e) {
    echo $name, ' ', get_class($e), "\n";
  }
}
