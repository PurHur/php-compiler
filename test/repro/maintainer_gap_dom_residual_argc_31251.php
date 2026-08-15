<?php
/**
 * #31251 — DOM residual methods excess argc → Zend ArgumentCountError
 * (re-#31011 / #31032 / #31090 / #31091).
 *
 * php-src: ext/dom/php_dom.stub.php / node.c / document.c / element.c / xpath.c
 */
error_reporting(E_ALL);
function msg(callable $fn): void
{
    try {
        $fn();
        echo "NOERR\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}

$doc = new DOMDocument();
$doc->loadXML('<root xmlns:p="urn:p" id="r"><a/></root>');
$el = $doc->documentElement;
$attr = $doc->createAttribute('x');
$xp = new DOMXPath($doc);

msg(static function () use ($el) {
    $el->lookupPrefix('urn:p', 'x');
});
msg(static function () use ($el) {
    $el->lookupNamespaceURI('p', 'x');
});
msg(static function () use ($el) {
    $el->isDefaultNamespace('urn:p', 'x');
});
msg(static function () use ($el) {
    $el->isSupported('XML', '1.0', 'x');
});
msg(static function () use ($el) {
    $el->C14NFile('/tmp/c14n_31251.out', false, false, null, null, 'x');
});
msg(static function () use ($doc) {
    $doc->schemaValidate('/tmp/x.xsd', 0, 'x');
});
msg(static function () use ($doc) {
    $doc->schemaValidateSource('<xs/>', 0, 'x');
});
msg(static function () use ($doc) {
    $doc->relaxNGValidate('/tmp/x.rng', 'x');
});
msg(static function () use ($doc) {
    $doc->relaxNGValidateSource('<grammar/>', 'x');
});
msg(static function () use ($doc) {
    $doc->load('/tmp/x.xml', 0, 'x');
});
msg(static function () use ($doc) {
    $doc->save('/tmp/out.xml', 0, 'x');
});
msg(static function () use ($doc) {
    $doc->saveHTMLFile('/tmp/out.html', 'x');
});
msg(static function () use ($doc) {
    $doc->createCDATASection('c', 'x');
});
msg(static function () use ($doc) {
    $doc->createDocumentFragment('x');
});
msg(static function () use ($doc) {
    $doc->createEntityReference('amp', 'x');
});
msg(static function () use ($doc) {
    $doc->createProcessingInstruction('t', 'd', 'x');
});
msg(static function () use ($doc) {
    $doc->registerNodeClass('DOMElement', null, 'x');
});
msg(static function () use ($el, $attr) {
    $el->setAttributeNode($attr, 'x');
});
msg(static function () use ($el, $attr) {
    $el->removeAttributeNode($attr, 'x');
});
msg(static function () use ($xp) {
    $xp->registerPhpFunctions(null, 'x');
});

// Legal arities still work after surplus rejection.
echo $el->lookupPrefix('urn:p'), "\n";
echo $el->isSupported('XML', '1.0') ? "featOK\n" : "featBAD\n";
$frag = $doc->createDocumentFragment();
echo $frag instanceof DOMDocumentFragment ? "fragOK\n" : "fragBAD\n";
$xp->registerPhpFunctions();
echo "phpFnOK\n";
