--TEST--
SimpleXMLElement::registerXPathNamespace applied by AOT xpath() (#27534, ext/simplexml/simplexml.c)
--FILE--
<?php
$xml = simplexml_load_string('<r xmlns:n="http://n"><n:a>1</n:a></r>');
$xml->registerXPathNamespace('nn', 'http://n');
$n = $xml->xpath('//nn:a');
echo 'count=', count($n), ' val=', isset($n[0]) ? (string) $n[0] : 'MISSING', "\n";
?>
--EXPECT--
count=1 val=1
