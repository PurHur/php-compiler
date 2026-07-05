--TEST--
Language: readonly property default on anonymous class (PHP 8.3, issue #6724)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsReadonlyAnonymousClass()) {
    die('skip readonly anonymous class defaults require PHP 8.3+ forward profile');
}
?>
--FILE--
<?php
$o = new class {
    public readonly int $x = 1;
};
var_export($o->x);
--EXPECT--
1
