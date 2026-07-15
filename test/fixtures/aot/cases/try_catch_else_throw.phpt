--TEST--
Language: try/catch/else catch path skips else user-script AOT (#19148)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsTryCatchElse()) {
    die('skip try/catch/else requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
try {
    throw new Exception('x');
} catch (Throwable) {
    echo "catch";
} else {
    echo "else";
}
--EXPECT--
catch
