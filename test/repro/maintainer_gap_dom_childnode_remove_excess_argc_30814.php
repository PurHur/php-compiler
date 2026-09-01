<?php
/**
 * #30814 — ChildNode::remove() excess argc → Zend ArgumentCountError.
 *
 * Zend names: DOMElement::remove / DOMCharacterData::remove (user args exclude $this).
 */
error_reporting(E_ALL);

$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$el = $d->documentElement->firstChild;
$text = $d->createTextNode('t');
$d->documentElement->appendChild($text);
$comment = $d->createComment('c');
$d->documentElement->appendChild($comment);
$cdata = $d->createCDATASection('x');
$d->documentElement->appendChild($cdata);

try {
    $el->remove(1);
    echo "NOERR\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $text->remove(1);
    echo "NOERR\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $comment->remove(1);
    echo "NOERR\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $cdata->remove(1);
    echo "NOERR\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

// Zero-arg still detaches.
$d2 = new DOMDocument();
$d2->loadXML('<r><a/><b/></r>');
$a = $d2->documentElement->firstChild;
$a->remove();
echo preg_replace('/\s+/', '', $d2->saveXML($d2->documentElement)), "\n";
