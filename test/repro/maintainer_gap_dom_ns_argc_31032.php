<?php
/**
 * #31032 — DOM NS / id-attribute excess argc → Zend ArgumentCountError (re-#31011 / #30616).
 *
 * php-src: ext/dom/document.c, element.c / php_dom.stub.php
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

$dom = new DOMDocument();
$dom->loadXML('<r xmlns:a="urn:a"><a:x b="1"/></r>');
$el = $dom->documentElement;
$ns = 'urn:a';

msg(static function () use ($dom, $ns) {
    $dom->createElementNS($ns, 'a:y', 'v', 1);
});
msg(static function () use ($dom, $ns) {
    $dom->createAttributeNS($ns, 'a:b', 1);
});
msg(static function () use ($dom, $ns) {
    $dom->getElementsByTagNameNS($ns, 'x', 1);
});
msg(static function () use ($el, $ns) {
    $el->setAttributeNS($ns, 'a:c', '2', 1);
});
msg(static function () use ($el, $ns) {
    $el->removeAttributeNS($ns, 'b', 1);
});
msg(static function () use ($el, $ns) {
    $el->hasAttributeNS($ns, 'b', 1);
});
msg(static function () use ($el, $ns) {
    $el->getAttributeNodeNS($ns, 'b', 1);
});

$attr = $dom->createAttributeNS($ns, 'a:b');
msg(static function () use ($el, $attr) {
    $el->setAttributeNodeNS($attr, 1);
});
msg(static function () use ($el, $ns) {
    $el->setIdAttributeNS($ns, 'b', true, 1);
});
msg(static function () use ($el, $attr) {
    $el->setIdAttributeNode($attr, true, 1);
});

// Legal arities still work.
$child = $dom->createElementNS($ns, 'a:y', 'v');
echo $child instanceof DOMElement ? 'createNSOK' : 'createNSFAIL', "\n";
echo $dom->createAttributeNS($ns, 'a:c') instanceof DOMAttr ? 'createAttrNSOK' : 'createAttrNSFAIL', "\n";
$list = $dom->getElementsByTagNameNS($ns, 'x');
echo $list instanceof DOMNodeList ? 'tagNSOK' : 'tagNSFAIL', "\n";
$el->setAttributeNS($ns, 'a:c', '2');
echo '2' === $el->getAttributeNS($ns, 'c') ? 'setNSOK' : 'setNSFAIL', "\n";
echo $el->hasAttributeNS($ns, 'c') ? 'hasNSOK' : 'hasNSFAIL', "\n";
