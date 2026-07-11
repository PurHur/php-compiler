--TEST--
Language: bare throw; escapes to uncaught handler (#3508)
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
class Ex extends Exception {
    public string $message = 'orig';
}

try {
    throw new Ex();
} catch (Ex $e) {
    throw;
}
--EXPECT_EXIT--
255
