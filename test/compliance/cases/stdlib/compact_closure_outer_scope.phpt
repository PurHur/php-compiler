--TEST--
stdlib compact() inside closure — outer locals absent + Warning (#25898, array.c)
--FILE--
<?php
declare(strict_types=1);
set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});
$b = 'OUTER';
$r = (function () {
    $a = 1;

    return compact('a', 'b');
})();
$keys = array_keys($r);
sort($keys);
var_export($keys);
echo "\n";
echo array_key_exists('b', $r) ? "has_b\n" : "no_b\n";
$withUse = (function () use ($b) {
    $a = 2;

    return compact('a', 'b');
})();
var_export($withUse);
echo "\n";
function compact_with_global(): array
{
    global $b;
    $a = 3;

    return compact('a', 'b');
}
var_export(compact_with_global());
echo "\n";
--EXPECT--
W:compact(): Undefined variable $b
array (
  0 => 'a',
)
no_b
array (
  'a' => 2,
  'b' => 'OUTER',
)
array (
  'a' => 3,
  'b' => 'OUTER',
)
