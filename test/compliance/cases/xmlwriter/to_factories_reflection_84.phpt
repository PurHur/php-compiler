--TEST--
xmlwriter XMLWriter::toMemory/toUri/toStream Reflection + named args (#27922, ext/xmlwriter/php_xmlwriter.stub.php)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsXmlWriterFactories()) {
    die('skip XMLWriter factories require PHP_COMPILER_PROFILE=8.4 (#27922)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach (['toMemory', 'toUri', 'toStream'] as $m) {
    $rf = new ReflectionMethod(XMLWriter::class, $m);
    echo $m, ' arity=', $rf->getNumberOfParameters(),
        ' req=', $rf->getNumberOfRequiredParameters(),
        ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none',
        ' static=', $rf->isStatic() ? '1' : '0',
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

$w = XMLWriter::toMemory();
echo 'toMemory=', $w instanceof XMLWriter ? '1' : '0', "\n";

$path = sys_get_temp_dir().'/phpc_xmlwriter_to_uri_named_'.uniqid().'.xml';
$w2 = XMLWriter::toUri(uri: $path);
$w2->startElement('u');
$w2->endElement();
$w2->endDocument();
echo 'named_uri=', is_file($path) ? '1' : '0', "\n";
@unlink($path);

$stream = fopen('php://memory', 'w+');
$w3 = XMLWriter::toStream(stream: $stream);
$w3->startElement('s');
$w3->text('z');
$w3->endElement();
$w3->endDocument();
rewind($stream);
echo 'named_stream=', trim(stream_get_contents($stream)), "\n";
fclose($stream);
?>
--EXPECT--
toMemory arity=0 req=0 ret=static static=1
  params=
toUri arity=1 req=1 ret=static static=1
  params=uri:string:REQ
toStream arity=1 req=1 ret=static static=1
  params=stream:-:REQ
toMemory=1
named_uri=1
named_stream=<s>z</s>
