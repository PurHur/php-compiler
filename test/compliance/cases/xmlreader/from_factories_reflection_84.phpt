--TEST--
xmlreader XMLReader::fromString/fromUri/fromStream Reflection + named args (#27713, ext/xmlreader/php_xmlreader.stub.php)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsXmlReaderFactories()) {
    die('skip XMLReader factories require PHP_COMPILER_PROFILE=8.4 (#27713)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach (['fromString', 'fromUri', 'fromStream'] as $m) {
    $rf = new ReflectionMethod(XMLReader::class, $m);
    echo $m, ' arity=', $rf->getNumberOfParameters(),
        ' req=', $rf->getNumberOfRequiredParameters(),
        ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none',
        "\n";
    $parts = [];
    foreach ($rf->getParameters() as $p) {
        $parts[] = $p->getName()
            .':'
            .($p->hasType() ? (string) $p->getType() : '-')
            .':'.($p->isOptional() ? 'OPT' : 'REQ');
    }
    echo '  params=', implode(',', $parts), "\n";
}

$r = XMLReader::fromString(source: '<?xml version="1.0"?><a/>');
echo 'named_source=', $r instanceof XMLReader ? '1' : '0', "\n";

$tmp = tempnam(sys_get_temp_dir(), 'xr');
file_put_contents($tmp, '<?xml version="1.0"?><b/>');
$h = fopen($tmp, 'r');
$r2 = XMLReader::fromStream(stream: $h, documentUri: 'file://'.$tmp);
echo 'named_stream=', $r2 instanceof XMLReader ? '1' : '0', "\n";
fclose($h);

$r3 = XMLReader::fromUri(uri: $tmp);
echo 'named_uri=', $r3 instanceof XMLReader ? '1' : '0', "\n";
@unlink($tmp);
?>
--EXPECT--
fromString arity=3 req=1 ret=static
  params=source:string:REQ,encoding:?string:OPT,flags:int:OPT
fromUri arity=3 req=1 ret=static
  params=uri:string:REQ,encoding:?string:OPT,flags:int:OPT
fromStream arity=4 req=1 ret=static
  params=stream:-:REQ,encoding:?string:OPT,flags:int:OPT,documentUri:?string:OPT
named_source=1
named_stream=1
named_uri=1
