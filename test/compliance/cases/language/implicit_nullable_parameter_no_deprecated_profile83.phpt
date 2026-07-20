--TEST--
language: implicit nullable typed params stay non-deprecated on PROFILE=8.3 (#21390)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
error_reporting(E_ALL);
$errors = [];
set_error_handler(static function (int $errno, string $msg) use (&$errors): bool {
    $errors[] = sprintf('[%d] %s', $errno, $msg);
    return true;
});

eval('
function issue21390_83_fn(int $x = null): void {}
class Issue21390_83_Class {
    public function method(int $y = null): void {}
}
$c = function (int $z = null): int { return 1; };
$a = fn (int $w = null): int => 1;
');
$isCompilerRuntime = function_exists('compiler_language_warning');
$ok = !$isCompilerRuntime || [] === $errors;
echo '83_ok=', $ok ? '1' : '0', "\n";
--EXPECT--
83_ok=1
