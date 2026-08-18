--TEST--
stdlib DOMDocument::importNode(loadXML documentElement) nodeName + saveXML (#32350)
--FILE--
<?php
$src = new DOMDocument();
$src->loadXML('<r><c/></r>');
$dst = new DOMDocument();
$n = $dst->importNode($src->documentElement, true);
echo $n->nodeName, '|', $dst->saveXML($n), "END\n";
?>
--EXPECT--
r|<r><c/></r>END
