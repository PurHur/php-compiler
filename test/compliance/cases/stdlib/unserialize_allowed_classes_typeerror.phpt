--TEST--
stdlib unserialize() — wrong-type allowed_classes / max_depth → TypeError (#24149, ext/standard/var.c)
--FILE--
<?php
foreach ([
  'string' => ['allowed_classes' => 'nope'],
  'object' => ['allowed_classes' => new stdClass()],
  'int' => ['allowed_classes' => 1],
] as $n => $opts) {
  try {
    unserialize('O:8:"stdClass":0:{}', $opts);
    echo "$n OK\n";
  } catch (Throwable $e) {
    echo "$n ", get_class($e), ': ', $e->getMessage(), "\n";
  }
}
try {
  unserialize('a:0:{}', ['max_depth' => 'nope']);
  echo "max_depth OK\n";
} catch (Throwable $e) {
  echo 'max_depth ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
  unserialize('a:0:{}', 'nope');
  echo "options OK\n";
} catch (Throwable $e) {
  echo 'options ', get_class($e), ': ', $e->getMessage(), "\n";
}
$ok = unserialize('O:8:"stdClass":0:{}', ['allowed_classes' => true]);
echo 'true ', get_class($ok), "\n";
$deny = unserialize('O:8:"stdClass":0:{}', ['allowed_classes' => false]);
echo 'false ', get_class($deny), "\n";
--EXPECT--
string TypeError: unserialize(): Option "allowed_classes" must be of type array|bool, string given
object TypeError: unserialize(): Option "allowed_classes" must be of type array|bool, stdClass given
int TypeError: unserialize(): Option "allowed_classes" must be of type array|bool, int given
max_depth TypeError: unserialize(): Option "max_depth" must be of type int, string given
options TypeError: unserialize(): Argument #2 ($options) must be of type array, string given
true stdClass
false __PHP_Incomplete_Class
