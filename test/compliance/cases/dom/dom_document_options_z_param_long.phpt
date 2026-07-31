--TEST--
DOMDocument load*/save*/schema*/xinclude int options Z_PARAM_LONG coerce (#25768)
--FILE--
<?php
$doc = new DOMDocument();
echo "loadHTML=", ((new DOMDocument())->loadHTML('<p>x</p>', '0') ? 'ok' : 'bad'), "\n";
echo "loadXML=", ((new DOMDocument())->loadXML('<r/>', '0') ? 'ok' : 'bad'), "\n";
$doc->loadXML('<r/>');
echo "saveXML=", (str_contains($doc->saveXML(null, '0'), '<r') ? 'ok' : 'bad'), "\n";
echo "xinclude=", var_export($doc->xinclude('0'), true), "\n";
$xsd = '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">'
    . '<xs:element name="r" type="xs:string"/></xs:schema>';
$d2 = new DOMDocument();
$d2->loadXML('<r>x</r>');
echo "schema=", ($d2->schemaValidateSource($xsd, '0') ? 'ok' : 'bad'), "\n";
try {
    (new DOMDocument())->loadHTML('<p>x</p>', []);
    echo "array=ok\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'must be of type int, array given') ? 'array=te' : 'array=bad'), "\n";
}
--EXPECT--
loadHTML=ok
loadXML=ok
saveXML=ok
xinclude=false
schema=ok
array=te
