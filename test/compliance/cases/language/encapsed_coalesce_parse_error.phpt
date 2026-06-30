--TEST--
Language: encapsed null coalesce in double-quoted strings must parse-error (#14032)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsEncapsedCoalesce()) {
    die('skip PHP 8.4+ allows ?? in encapsed interpolation (#14063)');
}
?>
--FILE--
<?php

declare(strict_types=1);

$a = ['b' => 1];
echo "{$a['b'] ?? 0}";
--EXPECT_EXIT--
255
