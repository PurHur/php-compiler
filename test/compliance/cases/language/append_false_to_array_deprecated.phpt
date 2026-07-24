--TEST--
Language: []= on false emits E_DEPRECATED then promotes (#22828, zend_execute.c)
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $errno, string $msg) use (&$seen): bool {
    if (E_DEPRECATED === $errno) {
        $seen[] = $msg;
    }
    return true;
});

$f = false;
$f[] = 1;
$ok = 1 === count($seen)
    && str_contains($seen[0], 'Automatic conversion of false to array is deprecated')
    && is_array($f)
    && [1] === $f;
echo $ok ? "append_ok\n" : "append_bad\n";

$g = false;
$g['k'] = 2;
$okKey = 2 === count($seen)
    && str_contains($seen[1], 'Automatic conversion of false to array is deprecated')
    && ['k' => 2] === $g;
echo $okKey ? "keyed_ok\n" : "keyed_bad\n";

$n = null;
$n[] = 3;
echo 'null_count=', count($seen), "\n";
var_export($n);
echo "\n";

try {
    $t = true;
    $t[] = 1;
    echo "true-ok\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
append_ok
keyed_ok
null_count=2
array (
  0 => 3,
)
Error: Cannot use a scalar value as an array
