--TEST--
language: optional param before required emits E_DEPRECATED (zend_compile.c, #31904)
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
function issue31904_fn($a = 1, $b) {}
function issue31904_mid($a, $b = 2, $c) {}
class Issue31904_Class {
    public function method($a = 1, $b) {}
}
abstract class Issue31904_Abstract {
    abstract public function abs($a = 1, $b);
}
$c = function ($a = 1, $b) { return 1; };
$a = fn($a = 1, $b) => 1;
function issue31904_implicit(int $x = null, $y) {}
function issue31904_explicit(?int $x = null, $y) {}
');

$ab = $bc = $xy = 0;
foreach ($errors as $entry) {
    if (str_contains($entry, 'Optional parameter $a declared before required parameter $b is implicitly treated as a required parameter')) {
        $ab++;
    }
    if (str_contains($entry, 'Optional parameter $b declared before required parameter $c is implicitly treated as a required parameter')) {
        $bc++;
    }
    if (str_contains($entry, 'Optional parameter $x declared before required parameter $y is implicitly treated as a required parameter')) {
        $xy++;
    }
}

$ace = '';
try {
    issue31904_fn(b: 2);
} catch (ArgumentCountError $e) {
    $ace = $e->getMessage();
}

$isCompilerRuntime = function_exists('compiler_language_warning');
$ok = !$isCompilerRuntime || ($ab === 5 && $bc === 1 && $xy === 1
    && $ace === 'issue31904_fn(): Argument #1 ($a) not passed');
echo '31904_ok=', $ok ? '1' : '0', "\n";
echo 'ace=', $ace, "\n";
echo 'ab=', $ab, ' bc=', $bc, ' xy=', $xy, "\n";
--EXPECT--
31904_ok=1
ace=issue31904_fn(): Argument #1 ($a) not passed
ab=5 bc=1 xy=1
