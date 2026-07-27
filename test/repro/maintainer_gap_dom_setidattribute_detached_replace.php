<?php
// #23999 — setIdAttribute on a detached element must not resolve via getElementById
// until the node is connected (php-src bug 77686 / tree walk). After replaceChild,
// Zend finds the node; removeChild clears it again.
// Control: setIdAttribute on an already-attached node resolves immediately.
$doc = new DOMDocument();
$doc->loadXML('<root><a id="old"/></root>');
$a = $doc->documentElement->firstChild;
$b = $doc->createElement('b');
$b->setAttribute('id', 'x');
$b->setIdAttribute('id', true);
echo 'before=', $doc->getElementById('x') ? $doc->getElementById('x')->nodeName : 'null', "\n";
$doc->documentElement->replaceChild($b, $a);
$found = $doc->getElementById('x');
echo 'after=', $found ? $found->nodeName : 'null', "\n";

$c = $doc->createElement('c');
$c->setAttribute('id', 'y');
$doc->documentElement->appendChild($c);
$c->setIdAttribute('id', true);
echo 'attached=', $doc->getElementById('y') ? $doc->getElementById('y')->nodeName : 'null', "\n";

$d = $doc->getElementById('y');
$doc->documentElement->removeChild($d);
echo 'removed=', $doc->getElementById('y') ? $doc->getElementById('y')->nodeName : 'null', "\n";
