--TEST--
Property hook get($param) block syntax compiles and invokes via call syntax (#18172, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks disabled on reference profile');
}
?>
--FILE--
<?php
declare(strict_types=1);

class C {
    private string $_data = 'abcdef';

    public string $chunk {
        get ($len) {
            return substr($this->_data, 0, $len);
        }
    }
}

echo (new C())->chunk(3), "\n";
--EXPECT--
abc
