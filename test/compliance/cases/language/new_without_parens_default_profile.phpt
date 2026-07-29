--TEST--
Language: `new Class()->method()` on default 8.4.0-dev forward profile (#24755, RFC new_without_parentheses)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
    die('skip requires PHP 8.4+ dereferencable new forward profile');
}
$profile = getenv('PHP_COMPILER_PROFILE');
if (\is_string($profile) && '' !== trim($profile) && '8.4' !== trim($profile)) {
    die('skip requires unset PHP_COMPILER_PROFILE or PROFILE=8.4');
}
?>
--FILE--
<?php
class Builder {
    public function build(): string { return 'built'; }
}
echo new Builder()->build(), "\n";
--EXPECT--
built
