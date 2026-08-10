--TEST--
Language: set-only hooked property type narrow Fatal cites $prop::set() (#29690, zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
class A {
  public string|int $prop {
    set(string|int $v) {}
  }
}
class B extends A {
  public string $prop {
    set(string $v) {}
  }
}
echo "survived\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Declaration of B::$prop::set(string $v): void must be compatible with A::$prop::set(string|int $v): void in %s on line %d
