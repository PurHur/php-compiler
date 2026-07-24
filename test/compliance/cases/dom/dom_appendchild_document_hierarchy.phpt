--TEST--
DOMElement::appendChild(DOMDocument) — Hierarchy Request Error not TypeError (#22698)
--FILE--
<?php
$d = new DOMDocument();
$e = $d->createElement('a');
$d->appendChild($e);
echo 'instanceof DOMNode=', $d instanceof DOMNode ? 'yes' : 'no', "\n";
try {
    $e->appendChild($d);
    echo "unexpected_ok\n";
} catch (Throwable $ex) {
    echo get_class($ex), ': ', $ex->getMessage(), ' code=', $ex->getCode(), "\n";
}
echo 'DOM_HIERARCHY_REQUEST_ERR=', DOM_HIERARCHY_REQUEST_ERR, "\n";
--EXPECT--
instanceof DOMNode=yes
DOMException: Hierarchy Request Error code=3
DOM_HIERARCHY_REQUEST_ERR=3
