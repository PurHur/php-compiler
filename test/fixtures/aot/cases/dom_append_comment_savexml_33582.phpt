--TEST--
AOT: appendChild(comment) saveXML (#33582)
--FILE--
<?php
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$c = $d->createComment('c');
$r->appendChild($c);
echo $c->nodeName, '|', $d->saveXML($r), "\n";
?>
--EXPECT--
#comment|<r><!--c--></r>
