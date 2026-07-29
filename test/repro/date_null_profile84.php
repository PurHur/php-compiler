<?php
// #21491/#24862/#21582 — idate/strftime/date_parse/mktime/gmmktime soft-null under PROFILE=8.4
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
    } elseif ('date_parse' === $name) {
      echo $name, ' COERCE error_count=', $r['error_count'], "\n";
    } else {
      echo $name, " COERCE\n";
    }
  } catch (Throwable $e) {
    echo $name, ' ', get_class($e), "\n";
  }
}
