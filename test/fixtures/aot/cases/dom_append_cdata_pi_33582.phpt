--TEST--
AOT: appendChild(CDATA/PI) saveXML (#33582)
--FILE--
<?php
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$r->appendChild($d->createCDATASection('x'));
$r->appendChild($d->createProcessingInstruction('t', 'd'));
echo $d->saveXML($r), "\n";
?>
--EXPECT--
<r><![CDATA[x]]><?t d?></r>
