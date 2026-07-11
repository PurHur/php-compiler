--TEST--
Language: bare throw; + non-capturing catch (Type) (#15299, Zend/zend_compile.c)
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
declare(strict_types=1);

class Inner extends Exception {}

try {
    throw new Inner('inner');
} catch (Inner) {
    echo "caught\n";
}

try {
    try {
        throw new Inner('rethrow');
    } catch (Inner) {
        throw;
    }
} catch (Inner $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
caught
rethrow
