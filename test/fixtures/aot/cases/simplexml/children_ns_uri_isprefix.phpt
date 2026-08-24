--TEST--
SimpleXML children($uri) defaults isPrefix=false (#34554)
--FILE--
<?php
$s = new SimpleXMLElement('<r/>');
$s->addChild('x', '1', 'urn:x');
echo count($s->children('urn:x')), "\n";
echo count($s->children('urn:x', false)), "\n";
echo count($s->children('urn:x', true)), "\n";
?>
--EXPECT--
1
1
0
