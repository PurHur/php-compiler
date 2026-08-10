--TEST--
Language: hooked property type widen Fatal cites $prop::get() (#29690, zend_inheritance.c)
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
  public string $prop {
    get => "a";
    set(string $v) {}
  }
}
class B extends A {
  public string|int $prop {
    get => 1;
    set(string|int $v) {}
  }
}
echo "survived\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Declaration of B::$prop::get(): string|int must be compatible with A::$prop::get(): string in %s on line %d
