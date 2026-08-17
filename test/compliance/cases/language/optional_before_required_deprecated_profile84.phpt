--TEST--
language: optional-before-required E_DEPRECATED prefixes callable on PROFILE=8.4 (#31904)
--ENV--
PHP_COMPILER_PROFILE=8.4
--INI--
error_reporting=E_ALL
--FILE--
<?php
error_reporting(E_ALL);
$errors = [];
set_error_handler(static function (int $errno, string $msg) use (&$errors): bool {
    $errors[] = sprintf('[%d] %s', $errno, $msg);
    return true;
});

eval('
function issue31904_84_fn($a = 1, $b) {}
class Issue31904_84_Class {
    public function method($a = 1, $b) {}
}
$c = function ($a = 1, $b) { return 1; };
');

$hasFn = $hasMethod = $hasClosure = false;
foreach ($errors as $entry) {
    if (str_contains($entry, 'issue31904_84_fn(): Optional parameter $a declared before required parameter $b is implicitly treated as a required parameter')) {
        $hasFn = true;
    }
    if (str_contains($entry, 'Issue31904_84_Class::method(): Optional parameter $a declared before required parameter $b is implicitly treated as a required parameter')) {
        $hasMethod = true;
    }
    if (str_contains($entry, '{closure:')
        && str_contains($entry, '}(): Optional parameter $a declared before required parameter $b is implicitly treated as a required parameter')
    ) {
        $hasClosure = true;
    }
}
$isCompilerRuntime = function_exists('compiler_language_warning');
$ok = !$isCompilerRuntime || ($hasFn && $hasMethod && $hasClosure);
echo '84_ok=', $ok ? '1' : '0', "\n";
--EXPECT--
84_ok=1
