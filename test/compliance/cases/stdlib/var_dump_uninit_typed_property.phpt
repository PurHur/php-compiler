--TEST--
stdlib var_dump() — uninitialized typed properties as uninitialized(T) (#31147, ext/standard/var.c)
--FILE--
<?php
class C {
    public int $x;
    public string $s;
    public $y = 1;
}
$c = new C;
ob_start();
var_dump($c);
$out = ob_get_clean();
// Object handle is not stable across runtimes.
$out = preg_replace('/object\(C\)#\d+/', 'object(C)#N', $out);
echo $out;

echo "--- print_r omits uninit ---\n";
print_r($c);

echo "--- var_export omits uninit ---\n";
var_export($c);
echo "\n";

echo "--- get_object_vars omits uninit ---\n";
var_export(get_object_vars($c));
echo "\n";
--EXPECT--
object(C)#N (1) {
  ["x"]=>
  uninitialized(int)
  ["s"]=>
  uninitialized(string)
  ["y"]=>
  int(1)
}
--- print_r omits uninit ---
C Object
(
    [y] => 1
)
--- var_export omits uninit ---
\C::__set_state(array(
   'y' => 1,
))
--- get_object_vars omits uninit ---
array (
  'y' => 1,
)
