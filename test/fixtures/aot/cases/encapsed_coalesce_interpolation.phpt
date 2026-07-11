--TEST--
AOT: encapsed null coalesce in double-quoted strings — PHP 8.4 profile (#16643)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsEncapsedCoalesce()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
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
--EXPECT--
/test/script.php
1
nil
