--TEST--
SimpleXMLElement::xpath()[i]->getName() matches Zend (#34539)
--FILE--
<?php
$x = simplexml_load_string('<r><a>1</a><b>2</b></r>');
$r = $x->xpath('/r/b');
echo 'name=', $r[0]->getName(), '|str=', (string) $r[0], '|xml=', $r[0]->asXML(), "\n";
$abs = $x->xpath('/r/a');
echo 'a=', $abs[0]->getName(), "\n";
?>
--EXPECT--
name=b|str=2|xml=<b>2</b>
a=a
