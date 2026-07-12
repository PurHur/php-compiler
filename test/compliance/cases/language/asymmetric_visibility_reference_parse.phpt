--TEST--
Language: bare public private(set) before parenthesized form — reference profile parse line (#18062)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsAsymmetricVisibility()) {
    die('skip asymmetric visibility enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php

declare(strict_types=1);

class First {
    public private(set) int $x = 1;
}

class Second {
    public (private(set)) int $y = 2;
}
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Multiple access type modifiers are not allowed in %s on line %d
