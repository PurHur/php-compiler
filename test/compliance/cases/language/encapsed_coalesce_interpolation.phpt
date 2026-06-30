--TEST--
Language: encapsed null coalesce in double-quoted strings — PHP 8.4 (#14063)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsEncapsedCoalesce()) {
    die('skip requires PHP 8.4+ encapsed ?? interpolation');
}
?>
--FILE--
<?php

declare(strict_types=1);

$c = null;
echo "{$c ?? 'fallback'}";
echo "\n";
$a = ['b' => 1];
echo "{$a['b'] ?? 0}";
echo "\n";
echo "{$a['missing'] ?? 'nil'}";
echo "\n";
--EXPECT--
fallback
1
nil
