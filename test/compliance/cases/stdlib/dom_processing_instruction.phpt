--TEST--
DOMProcessingInstruction + createProcessingInstruction + saveXML round-trip (#6318)
--FILE--
<?php
echo class_exists('DOMProcessingInstruction', false) ? '1' : '0', "\n";
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$root->appendChild($doc->createCDATASection('<raw>'));
$pi = $doc->createProcessingInstruction('php', 'echo 1;');
$doc->appendChild($pi);
echo $pi::class, "\n";
echo $pi->target, "\n";
echo $pi->data, "\n";
echo $doc->saveXML($pi), "\n";
echo $doc->saveXML();
--EXPECT--
1
DOMProcessingInstruction
php
echo 1;
<?php echo 1;?>
<?xml version="1.0"?>
<root><![CDATA[<raw>]]></root>
<?php echo 1;?>
