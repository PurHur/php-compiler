--TEST--
ReflectionClass/Property/Function __toString (#22379)
--FILE--
<?php
class C { public $x = 1; function f() {} }
foreach ([
  new ReflectionClass('C'),
  new ReflectionProperty('C', 'x'),
  new ReflectionFunction('strlen'),
  new ReflectionMethod('C', 'f'),
] as $o) {
  try {
    $s = (string) $o;
    $cls = get_class($o);
    $ok = false;
    if ($cls === 'ReflectionClass') {
      $ok = str_starts_with($s, 'Class [') && strlen($s) > 0;
    } elseif ($cls === 'ReflectionProperty') {
      $ok = str_starts_with($s, 'Property [') && strlen($s) > 0;
    } elseif ($cls === 'ReflectionFunction') {
      $ok = str_starts_with($s, 'Function [') && strlen($s) > 0;
    } elseif ($cls === 'ReflectionMethod') {
      $ok = str_starts_with($s, 'Method [') && strlen($s) > 0;
    }
    echo $cls, ' ', $ok ? 'OK' : 'BAD', ' ', strlen($s), "\n";
  } catch (Throwable $e) {
    echo get_class($o), " FAIL\n";
  }
}
?>
--EXPECTF--
ReflectionClass OK %d
ReflectionProperty OK %d
ReflectionFunction OK %d
ReflectionMethod OK %d
