--TEST--
dom getElementById() updates after setAttribute/removeAttribute id (#19870, ext/dom/element.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadHTML('<html><body><div id="a">x</div></body></html>', LIBXML_NOERROR);
$el = $doc->getElementById('a');
echo 'before=', (null !== $el ? $el->textContent : 'null'), "\n";
$el->setAttribute('id', 'b');
$b = $doc->getElementById('b');
$a = $doc->getElementById('a');
echo 'after_b=', (null !== $b ? '1' : '0'), "\n";
echo 'after_a=', (null !== $a ? '1' : '0'), "\n";
$el->removeAttribute('id');
$a2 = $doc->getElementById('a');
$b2 = $doc->getElementById('b');
echo 'after_rm_a=', (null !== $a2 ? '1' : '0'), "\n";
echo 'after_rm_b=', (null !== $b2 ? '1' : '0'), "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<r><e foo="a"/></r>');
$el2 = $doc2->documentElement->firstChild;
$el2->setIdAttribute('foo', true);
$el2->setAttribute('foo', 'b');
$sb = $doc2->getElementById('b');
$sa = $doc2->getElementById('a');
echo 'setid_b=', (null !== $sb ? '1' : '0'), "\n";
echo 'setid_a=', (null !== $sa ? '1' : '0'), "\n";
$el2->removeAttribute('foo');
$sr = $doc2->getElementById('b');
echo 'setid_rm=', (null !== $sr ? '1' : '0'), "\n";
--EXPECT--
before=x
after_b=1
after_a=0
after_rm_a=0
after_rm_b=0
setid_b=1
setid_a=0
setid_rm=0
