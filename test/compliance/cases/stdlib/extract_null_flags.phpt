--TEST--
stdlib extract() null $flags — Z_PARAM_LONG soft-null DEP then EXTR_OVERWRITE (#31194, ext/standard/array.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
$arr = ['a' => 1];
$n = extract($arr, null);
var_export($n);
echo "\n";
echo 'a=', $a ?? 'undef', "\n";
$src = ['b' => 2];
$bad = [];
try {
    extract($src, $bad);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
ERR[8192]: extract(): Passing null to parameter #2 ($flags) of type int is deprecated
1
a=1
TypeError:extract(): Argument #2 ($flags) must be of type int, array given
