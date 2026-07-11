--TEST--
Language: bare throw; rethrows caught exception (#3508)
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
class Ex extends Exception {}

try {
    try {
        throw new Ex();
    } catch (Ex $e) {
        throw;
    }
} catch (Ex $e) {
    echo "ok\n";
}
--EXPECT--
ok
