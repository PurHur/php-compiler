--TEST--
Language: typed trait constants compile on 8.3+ target (#5993, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsTypedTraitConstants()) {
    die('skip typed trait constants require CompilerVersion 8.3+');
}
?>
--FILE--
<?php
trait T { public const string LABEL = 'ok'; }
class C { use T; }
echo C::LABEL, "\n";
--EXPECT--
ok
