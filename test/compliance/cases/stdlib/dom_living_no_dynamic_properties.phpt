--TEST--
Stdlib: Dom\ living nodes reject dynamic properties (Error; #26055, ext/dom/php_dom.c)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#26055)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = Dom\HTMLDocument::createFromString('<p id="p">x</p>', LIBXML_NOERROR);
$p = $d->getElementById('p');
try {
    $p->totallyFake = 'v';
    echo "WRITE_OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo 'isset=', isset($p->totallyFake) ? '1' : '0', "\n";

try {
    $p->outerHTML = '<span>y</span>';
    echo "OUTER_OK\n";
} catch (Throwable $e) {
    echo 'outer:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $d->foo = 1;
    echo "DOC_OK\n";
} catch (Throwable $e) {
    echo 'doc:', get_class($e), ':', $e->getMessage(), "\n";
}

$text = $p->firstChild;
try {
    $text->foo = 1;
    echo "TEXT_OK\n";
} catch (Throwable $e) {
    echo 'text:', get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
Error:Cannot create dynamic property Dom\HTMLElement::$totallyFake
isset=0
outer:Error:Cannot create dynamic property Dom\HTMLElement::$outerHTML
doc:Error:Cannot create dynamic property Dom\HTMLDocument::$foo
text:Error:Cannot create dynamic property Dom\Text::$foo
