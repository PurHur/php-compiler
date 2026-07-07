--TEST--
Language: encapsed null coalesce in double-quoted strings — PHP 8.4 (#14063, #14113, #16643)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsEncapsedCoalesce()) {
    die('skip requires PHP 8.4+ encapsed ?? interpolation');
}
// Native PHPUnit PHPT runner executes --FILE-- with host Zend; skip when syntax unavailable.
if (PHP_VERSION_ID < 80400) {
    die('skip encapsed ?? interpolation requires PHP 8.4+ Zend for native PHPT');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php

declare(strict_types=1);

// Maintainer repro: test/repro/maintainer_gap_string_interp_coalesce.php
$_SERVER['PHP_SELF'] = '/test/script.php';
echo "{$_SERVER['PHP_SELF'] ?? 'fallback'}";
echo "\n";
$a = ['b' => 1];
echo "{$a['b'] ?? 0}";
echo "\n";
echo "{$a['missing'] ?? 'nil'}";
echo "\n";
$obj = null;
echo "{$obj?->prop ?? 'def'}";
echo "\n";
echo "x{$a['k1'] ?? '1'}y{$a['k2'] ?? '2'}z";
echo "\n";
$name = null;
echo "${name ?? 'dollar'}";
echo "\n";
echo "{$name ?? 'brace'}";
echo "\n";
--EXPECT--
/test/script.php
1
nil
def
x1y2z
dollar
brace
