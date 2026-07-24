--TEST--
Language: pipe operator |> on forward profile PHP 8.5 (#16675, #22792, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsPipeOperator()) {
    die('skip requires PHP 8.5+ pipe operator forward profile');
}
if (PHP_VERSION_ID < 80500) {
    die('skip pipe operator requires PHP 8.5+ Zend for native PHPT');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php

declare(strict_types=1);

$x = 5 |> fn ($v) => $v * 2;
var_export($x);
echo "\n";
echo "hi" |> strtoupper(...);
echo "\n";
--EXPECT--
10
HI
