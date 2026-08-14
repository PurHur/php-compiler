--TEST--
Language: eval(final promoted) catchable ParseError under PROFILE=8.2 (#31153, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.2');
if (PHPCompiler\CompilerVersion::supportsFinalProperties()) {
    die('skip PROFILE=8.2 unexpectedly enables 8.4 final-on-parameter grammar (#31153)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
try {
    eval('class C { public function __construct(final public int $x) {} }');
    echo "accepted\n";
} catch (ParseError $e) {
    echo 'ParseError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "after\n";
--EXPECT--
ParseError:syntax error, unexpected token "final", expecting variable
after
