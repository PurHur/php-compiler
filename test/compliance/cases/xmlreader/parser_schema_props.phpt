--TEST--
xmlreader setParserProperty/getParserProperty/setSchema/setRelaxNGSchema (#19553, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
$r = new XMLReader();
foreach (['setParserProperty', 'getParserProperty', 'setSchema', 'setRelaxNGSchema'] as $m) {
    echo $m, '=', method_exists($r, $m) ? 'yes' : 'no', "\n";
}

$xsd = tempnam(sys_get_temp_dir(), 'xsd');
file_put_contents($xsd, '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="r" type="xs:string"/></xs:schema>');
$rng = tempnam(sys_get_temp_dir(), 'rng');
file_put_contents($rng, '<grammar xmlns="http://relaxng.org/ns/structure/1.0"><start><element name="r"><text/></element></start></grammar>');

$r1 = new XMLReader();
$r1->XML('<r>ok</r>');
echo 'setSchema=', (int) $r1->setSchema($xsd), "\n";
while ($r1->read()) {
}
echo 'schemaValid=', (int) $r1->isValid(), "\n";

$r2 = new XMLReader();
$r2->XML('<r>ok</r>');
echo 'setLOADDTD=', (int) $r2->setParserProperty(XMLReader::LOADDTD, true), "\n";
echo 'getLOADDTD=', (int) $r2->getParserProperty(XMLReader::LOADDTD), "\n";
echo 'getVALIDATE=', (int) $r2->getParserProperty(XMLReader::VALIDATE), "\n";

$r3 = new XMLReader();
$r3->XML('<r>ok</r>');
echo 'setRelaxNG=', (int) $r3->setRelaxNGSchema($rng), "\n";
while ($r3->read()) {
}
echo 'rngValid=', (int) $r3->isValid(), "\n";

unlink($xsd);
unlink($rng);
?>
--EXPECT--
setParserProperty=yes
getParserProperty=yes
setSchema=yes
setRelaxNGSchema=yes
setSchema=1
schemaValid=1
setLOADDTD=1
getLOADDTD=1
getVALIDATE=0
setRelaxNG=1
rngValid=1
