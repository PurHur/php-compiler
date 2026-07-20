<?php
/**
 * #19319 / #21420 — mixed soft-null on PROFILE=8.4 (php-src string.c).
 * convert_uudecode(null) DEP+coerces then invalid-empty → false (not TypeError).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
$fns = [
  'addcslashes' => fn () => addcslashes(null, 'a'),
  'stripslashes' => fn () => stripslashes(null),
  'hebrev' => fn () => hebrev(null),
  'str_split' => fn () => str_split(null),
  'convert_uudecode' => fn () => convert_uudecode(null),
  'str_getcsv' => fn () => str_getcsv(null),
  'ord' => fn () => ord(null),
];
foreach ($fns as $name => $fn) {
  try {
    $r = $fn();
    echo "$name: coerce ", var_export($r, true), "\n";
  } catch (TypeError $e) {
    echo "$name: TypeError\n";
  } catch (Throwable $e) {
    echo "$name: ", get_class($e), ':', $e->getMessage(), "\n";
  }
}
