--TEST--
DOMElement::setAttribute() returns Attr; xmlns returns true (#24538)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r/>');
$el = $d->documentElement;
$a = $el->setAttribute('foo', 'bar');
echo ($a instanceof DOMAttr ? 'attr:'.$a->name.'='.$a->value : gettype($a)), "\n";
$b = $el->setAttribute('foo', 'baz');
echo ($b === $a ? 'same' : 'diff'), ':', $b->value, "\n";
$x = $el->setAttribute('xmlns', 'urn:x');
echo 'xmlns=', var_export($x, true), "\n";
$ns = $el->setAttributeNS('urn:n', 'n:x', '1');
echo 'ns=', var_export($ns, true), "\n";
?>
--EXPECT--
attr:foo=bar
same:baz
xmlns=true
ns=NULL
