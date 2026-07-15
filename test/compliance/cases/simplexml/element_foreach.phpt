--TEST--
SimpleXMLElement foreach over child elements implements Traversable (#19089, ext/simplexml/sxe.c)
--FILE--
<?php
$xml = simplexml_load_string('<root><a>1</a><b>2</b></root>');
$out = [];
foreach ($xml as $name => $child) {
    $out[] = (string) $name.(string) $child;
}
echo implode(',', $out), "\n";
echo $xml instanceof Traversable ? 'traversable' : 'not-traversable', "\n";
--EXPECT--
a1,b2
traversable
