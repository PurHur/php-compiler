--TEST--
xmlreader setRelaxNGSchemaSource in-memory grammar (#19940, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
$r = new XMLReader();
echo 'method=', method_exists($r, 'setRelaxNGSchemaSource') ? 'yes' : 'no', "\n";

$grammar = '<grammar xmlns="http://relaxng.org/ns/structure/1.0"><start><element name="r"><text/></element></start></grammar>';

$r1 = new XMLReader();
$r1->XML('<r>ok</r>');
echo 'set=', (int) $r1->setRelaxNGSchemaSource($grammar), "\n";
while ($r1->read()) {
}
echo 'valid=', (int) $r1->isValid(), "\n";

$r2 = new XMLReader();
$r2->XML('<bad/>');
echo 'setBad=', (int) $r2->setRelaxNGSchemaSource($grammar), "\n";
while ($r2->read()) {
}
echo 'validBad=', (int) $r2->isValid(), "\n";

$r3 = new XMLReader();
$r3->XML('<r/>');
echo 'clear=', (int) $r3->setRelaxNGSchemaSource(null), "\n";

try {
    $r4 = new XMLReader();
    $r4->XML('<r/>');
    $r4->setRelaxNGSchemaSource('');
    echo "empty=no\n";
} catch (ValueError $e) {
    echo 'empty=yes', "\n";
}

try {
    $r5 = new XMLReader();
    $r5->setRelaxNGSchemaSource($grammar);
    echo "fresh=no\n";
} catch (Error $e) {
    echo 'fresh=', $e->getMessage() === 'Schema must be set prior to reading' ? 'yes' : 'no', "\n";
}
?>
--EXPECT--
method=yes
set=1
valid=1
setBad=1
validBad=0
clear=1
empty=yes
fresh=yes
