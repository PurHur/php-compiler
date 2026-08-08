--TEST--
xmlreader XMLReader::open/XML Reflection encoding ?string + no return type (#28712, ext/xmlreader/php_xmlreader.stub.php)
--FILE--
<?php
declare(strict_types=1);

foreach (['open', 'XML'] as $m) {
    $rf = new ReflectionMethod(XMLReader::class, $m);
    echo $m, ' arity=', $rf->getNumberOfParameters(),
        ' req=', $rf->getNumberOfRequiredParameters(),
        ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none',
        "\n";
    $parts = [];
    foreach ($rf->getParameters() as $p) {
        $def = '<none>';
        if ($p->isDefaultValueAvailable()) {
            $def = var_export($p->getDefaultValue(), true);
        }
        $parts[] = $p->getName()
            .':'
            .($p->hasType() ? (string) $p->getType() : '-')
            .':'.($p->isOptional() ? 'OPT' : 'REQ')
            .':def='.$def;
    }
    echo '  params=', implode(',', $parts), "\n";
}

$tmp = tempnam(sys_get_temp_dir(), 'xr');
file_put_contents($tmp, '<?xml version="1.0"?><a/>');
$r = XMLReader::open(uri: $tmp, encoding: null);
echo 'named_open_null_enc=', $r instanceof XMLReader ? '1' : '0', "\n";
$r2 = XMLReader::XML(source: '<?xml version="1.0"?><b/>', encoding: null);
echo 'named_xml_null_enc=', $r2 instanceof XMLReader ? '1' : '0', "\n";
$inst = new XMLReader();
echo 'inst_open=', $inst->open(uri: $tmp, encoding: null) ? '1' : '0', "\n";
@unlink($tmp);
?>
--EXPECT--
open arity=3 req=1 ret=none
  params=uri:string:REQ:def=<none>,encoding:?string:OPT:def=NULL,flags:int:OPT:def=0
XML arity=3 req=1 ret=none
  params=source:string:REQ:def=<none>,encoding:?string:OPT:def=NULL,flags:int:OPT:def=0
named_open_null_enc=1
named_xml_null_enc=1
inst_open=1
