--TEST--
stdlib DOMNode PHP 8.4 methods — not advertised on PHP 8.2 reference profile (#17470, #18636, ext/dom/php_dom.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.2');
if (PHPCompiler\CompilerVersion::supportsDomNodeContains()) {
    die('skip PHP_COMPILER_PROFILE=8.2 unexpectedly enables DOMNode::contains');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
$doc = new DOMDocument();
$el = $doc->createElement('x');
$fail = false;
foreach (['contains', 'replaceChildren'] as $method) {
    if (method_exists($el, $method)) {
        $fail = true;
    }
}
echo $fail ? "fail\n" : "ok\n";
--EXPECT--
ok
