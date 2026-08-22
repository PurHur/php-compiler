--TEST--
AOT: isEqualNode variable-null → false; compareDocumentPosition TypeError (#33733, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$doc = new DOMDocument();
$doc->loadXML('<r><a id="x"/></r>');
$el = $doc->documentElement;
$n = null;
echo 'eq_var=', (int) $el->isEqualNode($n), "\n";
$miss = $doc->getElementById('nope');
echo 'eq_id=', (int) $el->isEqualNode($miss), "\n";
try {
    $el->compareDocumentPosition($n);
    echo "cdp=fail\n";
} catch (Throwable $ex) {
    echo 'cdp=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $el->compareDocumentPosition($miss);
    echo "cdp_id=fail\n";
} catch (Throwable $ex) {
    echo 'cdp_id=', get_class($ex), ':', $ex->getMessage(), "\n";
}
--EXPECT--
eq_var=0
eq_id=0
cdp=TypeError:DOMNode::compareDocumentPosition(): Argument #1 ($other) must be of type DOMNode, null given
cdp_id=TypeError:DOMNode::compareDocumentPosition(): Argument #1 ($other) must be of type DOMNode, null given
