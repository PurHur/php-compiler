--TEST--
Language: switch case/default semicolon emits E_DEPRECATED under PROFILE=8.5 (#26279, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsSwitchCaseSemicolonDeprecation()) {
    die('skip requires PHP 8.5+ switch case semicolon deprecation');
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

eval('$x = 1; switch ($x) { case 1; echo "hit\n"; break; }');
$okCase = 1 === count($seen)
    && str_contains($seen[0], 'Case statements followed by a semicolon')
    && str_contains($seen[0], 'use a colon (:) instead');
echo $okCase ? "case_semi_ok\n" : "case_semi_bad\n";

$seen = [];
eval('switch (0) { default; echo "def\n"; }');
$okDefault = 1 === count($seen)
    && str_contains($seen[0], 'Case statements followed by a semicolon');
echo $okDefault ? "default_semi_ok\n" : "default_semi_bad\n";

$seen = [];
eval('switch (1) { case 1: echo "colon\n"; break; }');
echo 'colon_warns=', count($seen), "\n";

$seen = [];
eval('enum E { case A; }');
echo 'enum_warns=', count($seen), "\n";
--EXPECT--
hit
case_semi_ok
def
default_semi_ok
colon
colon_warns=0
enum_warns=0
