--TEST--
Language: backtick shell-exec emits E_DEPRECATED under PROFILE=8.5 (#26280, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsBacktickShellExecDeprecation()) {
    die('skip requires PHP 8.5+ backtick shell-exec deprecation');
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

$out = null;
eval('$out = `true`;');
$ok = 1 === count($seen)
    && str_contains($seen[0], 'The backtick (`) operator is deprecated')
    && str_contains($seen[0], 'use shell_exec() instead');
echo $ok ? "backtick_ok\n" : "backtick_bad\n";
echo 'result_ok=', (is_string($out) || null === $out) ? '1' : '0', "\n";

$seen = [];
$out2 = shell_exec('true');
echo 'shell_exec_warns=', count($seen), "\n";
--EXPECT--
backtick_ok
result_ok=1
shell_exec_warns=0
