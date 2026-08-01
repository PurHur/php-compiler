--TEST--
Language: non-canonical casts emit E_DEPRECATED under PROFILE=8.5 (#26281, Zend/zend_language_scanner.l)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsNonCanonicalCastDeprecation()) {
    die('skip requires PHP 8.5+ non-canonical cast deprecation');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
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

eval('$v = (integer)1.5; echo "v=$v\n";');
$okInt = 1 === count($seen)
    && str_contains($seen[0], 'Non-canonical cast (integer)')
    && str_contains($seen[0], '(int) cast instead');
echo $okInt ? "integer_ok\n" : "integer_bad\n";

$seen = [];
eval('$v = (boolean)1; echo "b=" . ($v ? "1" : "0") . "\n";');
$okBool = 1 === count($seen)
    && str_contains($seen[0], 'Non-canonical cast (boolean)');
echo $okBool ? "boolean_ok\n" : "boolean_bad\n";

$seen = [];
eval('$v = (double)1; echo "d=$v\n";');
$okDouble = 1 === count($seen)
    && str_contains($seen[0], 'Non-canonical cast (double)');
echo $okDouble ? "double_ok\n" : "double_bad\n";

$seen = [];
eval('$v = (binary)"hi"; echo "s=$v\n";');
$okBinary = 1 === count($seen)
    && str_contains($seen[0], 'Non-canonical cast (binary)');
echo $okBinary ? "binary_ok\n" : "binary_bad\n";

$seen = [];
eval('$v = (int)1.5; echo "c=$v\n";');
echo 'canonical_warns=', count($seen), "\n";
--EXPECT--
v=1
integer_ok
b=1
boolean_ok
d=1
double_ok
s=hi
binary_ok
c=1
canonical_warns=0
