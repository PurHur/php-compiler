<?php
/**
 * #30814 — ChildNode::remove() excess argc → Zend ArgumentCountError.
 *
 * Zend names: DOMElement::remove / DOMCharacterData::remove (user args exclude $this).
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

$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$el = $d->documentElement->firstChild;
$text = $d->createTextNode('t');
$d->documentElement->appendChild($text);
$comment = $d->createComment('c');
$d->documentElement->appendChild($comment);
$cdata = $d->createCDATASection('x');
$d->documentElement->appendChild($cdata);

msg(static function () use ($el) {
    $el->remove(1);
});
msg(static function () use ($text) {
    $text->remove(1);
});
msg(static function () use ($comment) {
    $comment->remove(1);
});
msg(static function () use ($cdata) {
    $cdata->remove(1);
});

// Zero-arg still detaches.
$d2 = new DOMDocument();
$d2->loadXML('<r><a/><b/></r>');
$a = $d2->documentElement->firstChild;
$a->remove();
echo preg_replace('/\s+/', '', $d2->saveXML($d2->documentElement)), "\n";
