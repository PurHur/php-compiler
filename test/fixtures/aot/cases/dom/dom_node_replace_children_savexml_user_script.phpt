--TEST--
AOT: DOMNode::replaceChildren() rewrites saveXML INNER_XML under PHP 8.4 (#29409, re-#19507, ext/dom/parentnode.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$d->documentElement->replaceChildren($d->createElement('z'));
echo $d->saveXML($d->documentElement), "\n";
$d->documentElement->replaceChildren();
echo $d->saveXML($d->documentElement), "\n";
$d->documentElement->replaceChildren($d->createElement('z'), 'txt');
echo $d->saveXML($d->documentElement), "\n";
--EXPECT--
<r><z/></r>
<r/>
<r><z/>txt</r>
