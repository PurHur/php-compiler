--TEST--
Language: (void) statement cast under PROFILE=8.5 — Zend T_VOID_CAST (#28441)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.5');
if (!PHPCompiler\CompilerVersion::supportsVoidCast()) {
    die('skip PROFILE=8.5 must enable (void) cast (#28441)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
ini_set('error_reporting', '32767');
error_clear_last();
#[\NoDiscard]
function must_use(): int {
    return 42;
}
(void) must_use();
$last = error_get_last();
echo null === $last ? "ok\n" : "warn\n";
try {
    eval('$a = (void) strlen("x");');
    echo "assign_ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
ok
ParseError
