<?php
$fns = [
  'addcslashes' => fn () => addcslashes(null, 'a'),
  'stripslashes' => fn () => stripslashes(null),
  'hebrev' => fn () => hebrev(null),
  'str_split' => fn () => str_split(null),
  'convert_uudecode' => fn () => @convert_uudecode(null),
  'str_getcsv' => fn () => str_getcsv(null),
  'ord' => fn () => ord(null),
];
foreach ($fns as $name => $fn) {
  try {
    $fn();
    echo "$name: coerce\n";
  } catch (TypeError $e) {
    echo "$name: TypeError\n";
  } catch (Throwable $e) {
    echo "$name: ", get_class($e), ':', $e->getMessage(), "\n";
  }
}
