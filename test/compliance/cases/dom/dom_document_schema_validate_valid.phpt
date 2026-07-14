--TEST--
DOMDocument::schemaValidate()/relaxNGValidate() — valid on-disk schema returns true (#18806, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root/>');

$xsd = tempnam(sys_get_temp_dir(), 'xsd');
file_put_contents(
    $xsd,
    '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="root"/></xs:schema>'
);

$rng = tempnam(sys_get_temp_dir(), 'rng');
file_put_contents(
    $rng,
    '<?xml version="1.0"?><grammar xmlns="http://relaxng.org/ns/structure/1.0"><start><element name="root"><empty/></element></start></grammar>'
);

$schemaOk = $doc->schemaValidate($xsd);
$relaxOk = $doc->relaxNGValidate($rng);

unlink($xsd);
unlink($rng);

echo ($schemaOk ? 'schema-true' : 'schema-false'), "\n";
echo ($relaxOk ? 'relax-true' : 'relax-false'), "\n";
--EXPECT--
schema-true
relax-true
