--TEST--
SimpleXMLElement children() includes default-xmlns unprefixed children (#22737, re-#19342)
--FILE--
<?php
$xml = simplexml_load_string('<r xmlns="urn:d"><c>1</c></r>');
echo 'direct=', (string) $xml->c, "\n";
echo 'isset=', isset($xml->c) ? '1' : '0', "\n";
echo 'children_ns=', (string) $xml->children('urn:d')->c, "\n";
$c0 = $xml->children();
echo 'children0=', (string) $c0->c, "\n";
echo 'children0_count=', $c0->count(), "\n";

// Prefixed sibling still excluded from no-arg children(); undeclared xmlns="" stays.
$mixed = simplexml_load_string('<r xmlns="urn:d"><c>1</c><x:y xmlns:x="urn:x">2</x:y><d xmlns="">3</d></r>');
echo 'mixed=';
foreach ($mixed->children() as $k => $v) {
    echo $k, '=', (string) $v, ';';
}
echo "\n";
?>
--EXPECT--
direct=1
isset=1
children_ns=1
children0=1
children0_count=1
mixed=c=1;d=3;
