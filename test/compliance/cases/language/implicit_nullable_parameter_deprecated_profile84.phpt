--TEST--
language: implicit nullable typed params emit E_DEPRECATED on PROFILE=8.4 (#21390)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$errors = [];
set_error_handler(static function (int $errno, string $msg) use (&$errors): bool {
    $errors[] = sprintf('[%d] %s', $errno, $msg);
    return true;
});

eval('
function issue21390_84_fn(int $x = null): void {}
class Issue21390_84_Class {
    public function method(int $y = null): void {}
}
$c = function (int $z = null): int { return 1; };
$a = fn (int $w = null): int => 1;
');
$isCompilerRuntime = function_exists('compiler_language_warning');
$hasExpectedMessage = false;
foreach ($errors as $entry) {
    if (str_contains($entry, 'Implicitly marking parameter $')
        && str_contains($entry, 'as nullable is deprecated, the explicit nullable type must be used instead')
    ) {
        $hasExpectedMessage = true;
        break;
    }
}
$ok = !$isCompilerRuntime || (count($errors) === 4 && $hasExpectedMessage);
echo '84_ok=', $ok ? '1' : '0', "\n";
--EXPECT--
84_ok=1
