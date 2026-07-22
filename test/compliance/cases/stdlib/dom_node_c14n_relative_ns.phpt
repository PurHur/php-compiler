--TEST--
DOMNode::C14N()/C14NFile() return false for relative namespace URIs (#22378, ext/dom/node.c)
--FILE--
<?php
$rel = new DOMDocument();
$rel->loadXML('<r xmlns:p="u"><p:a>x</p:a></r>');
echo (false === @$rel->documentElement->C14N()) ? 'rel ' : 'rel-fail ';
$tmp = tempnam(sys_get_temp_dir(), 'c14nrel');
echo (false === @$rel->documentElement->C14NFile($tmp)) ? 'file ' : 'file-fail ';
@unlink($tmp);

$abs = new DOMDocument();
$abs->loadXML('<r xmlns="http://example.com"><a>x</a></r>');
$want = '<r xmlns="http://example.com"><a>x</a></r>';
echo (@$abs->documentElement->C14N() === $want) ? 'abs ' : 'abs-fail ';

$urn = new DOMDocument();
$urn->loadXML('<r xmlns:a="urn:a"><a:x>t</a:x></r>');
echo (@$urn->documentElement->C14N() === '<r xmlns:a="urn:a"><a:x>t</a:x></r>') ? 'urn ' : 'urn-fail ';

// Sibling relative NS still fails (document-wide libxml check).
$sib = new DOMDocument();
$sib->loadXML('<r><a xmlns:p="u">x</a><b>y</b></r>');
echo (false === @$sib->getElementsByTagName('b')->item(0)->C14N()) ? "sib\n" : "sib-fail\n";
?>
--EXPECT--
rel file abs urn sib
