--TEST--
Language: bare throw; rethrows caught exception to outer handler (#17691)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsBareRethrow()) {
    die('skip bare rethrow disabled on reference profile');
}
?>
--FILE--
<?php
try {
    try {
        throw new Exception('inner');
    } catch (Exception $e) {
        throw;
    }
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
inner
