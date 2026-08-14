<?php
/**
 * #31011 — residual DOM excess argc → Zend ArgumentCountError (re-#30616 / #30835).
 *
 * php-src: ext/dom/document.c, element.c, node.c, nodelist.c / php_dom.stub.php
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
$dom->loadXML('<r xmlns:x="urn:x"><a x:y="z">t</a></r>');
$el = $dom->documentElement->firstChild;
msg(static function () use ($dom) {
    $dom->normalizeDocument(1);
});
msg(static function () use ($el) {
    $el->getElementsByTagName('a', 1);
});
msg(static function () use ($el) {
    $el->getAttributeNS('urn:x', 'y', 1);
});
msg(static function () use ($el) {
    $el->hasAttributes(1);
});
msg(static function () use ($el) {
    $el->getNodePath(1);
});
msg(static function () use ($el) {
    $el->C14N(false, false, null, null, 1);
});
msg(static function () use ($dom) {
    $dom->getElementsByTagName('a')->count(1);
});

// Legal arities still work.
$dom->normalizeDocument();
$list = $dom->getElementsByTagName('a');
echo $list->length > 0 ? 'tagOK' : 'tagFAIL', "\n";
echo 'z' === $el->getAttributeNS('urn:x', 'y') ? 'attrOK' : 'attrFAIL', "\n";
echo $el->hasAttributes() ? 'hasAttrOK' : 'hasAttrFAIL', "\n";
echo is_string($el->getNodePath()) ? 'pathOK' : 'pathFAIL', "\n";
echo is_string($el->C14N()) ? 'c14nOK' : 'c14nFAIL', "\n";
echo 1 === $list->count() ? 'countOK' : 'countFAIL', "\n";
