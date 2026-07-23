--TEST--
DOMException Wrong Document Error code is 4 (#22658, php-src ext/dom/php_dom.c)
--FILE--
<?php
$d1 = new DOMDocument();
$d1->loadXML('<r/>');
$d2 = new DOMDocument();
$d2->loadXML('<r/>');
try {
    $d2->documentElement->appendChild($d1->createElement('x'));
    echo "no throw\n";
} catch (DOMException $e) {
    echo 'msg=' . $e->getMessage() . "\n";
    echo 'code=' . $e->getCode() . "\n";
    echo 'prop=' . $e->code . "\n";
}
echo 'const=' . DOM_WRONG_DOCUMENT_ERR . "\n";
?>
--EXPECT--
msg=Wrong Document Error
code=4
prop=4
const=4
