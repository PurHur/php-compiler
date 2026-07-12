--TEST--
stdlib DOMEntity/DOMNotation from DTD internal subset (#6320, ext/dom/php_dom.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

$xml = '<!DOCTYPE doc [<!ENTITY ent "val"><!NOTATION n PUBLIC "n" "u">]><doc>&ent;</doc>';
$doc = new DOMDocument();
$doc->loadXML($xml);
$ref = $doc->documentElement->firstChild;
echo (int) ($ref instanceof DOMEntityReference), "\n";
echo $ref->textContent, "\n";
echo (int) class_exists('DOMEntity'), "\n";
echo (int) class_exists('DOMNotation'), "\n";
$ent = $doc->doctype->entities->item(0);
echo (int) ($ent instanceof DOMEntity), "\n";
echo $ent->nodeName, "\n";
echo $ent->nodeType, "\n";
$n = $doc->doctype->notations->item(0);
echo (int) ($n instanceof DOMNotation), "\n";
echo $n->nodeName, "\n";
echo $n->publicId, "\n";
echo $n->systemId, "\n";
?>
--EXPECT--
1
val
1
1
1
ent
17
1
n
n
u
