--TEST--
Dom\Document::registerNodeClass accepts Dom\* bases (#26061, ext/dom/document.c)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\ living documents require PHP_COMPILER_PROFILE=8.4 (#26061)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class MyE extends Dom\Element {}
class MyH extends Dom\HTMLElement {}
class MyLegacy extends DOMElement {}

$xml = Dom\XMLDocument::createEmpty();
$xml->registerNodeClass(Dom\Element::class, MyE::class);
$el = $xml->createElement('z');
echo $el instanceof MyE ? "xml-custom\n" : "xml-bad\n";

$html = Dom\HTMLDocument::createEmpty();
$html->registerNodeClass(Dom\HTMLElement::class, MyH::class);
$div = $html->createElement('div');
echo $div instanceof MyH ? "html-custom\n" : "html-bad\n";

$html->registerNodeClass(Dom\Element::class, MyE::class);
$svg = $html->createElementNS('http://www.w3.org/2000/svg', 'svg');
echo $svg instanceof MyE ? "foreign-custom\n" : "foreign-bad\n";
$stillHtml = $html->createElement('p');
echo $stillHtml instanceof MyH ? "html-still\n" : "html-lost\n";

try {
    $xml->registerNodeClass(DOMElement::class, MyLegacy::class);
    echo "legacy-base-ok\n";
} catch (TypeError $e) {
    echo (str_starts_with($e->getMessage(), 'Dom\\Document::registerNodeClass():'))
        ? "legacy-base-reject\n"
        : "legacy-base-msg\n";
}

$legacy = new DOMDocument();
$legacy->registerNodeClass(DOMElement::class, MyLegacy::class);
$stock = $legacy->createElement('x');
echo $stock instanceof MyLegacy ? "legacy-ok\n" : "legacy-bad\n";
?>
--EXPECT--
xml-custom
html-custom
foreign-custom
html-still
legacy-base-reject
legacy-ok
