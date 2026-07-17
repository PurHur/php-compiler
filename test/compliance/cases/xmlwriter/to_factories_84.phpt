--TEST--
xmlwriter XMLWriter::toMemory/toUri/toStream — PHP 8.4 factories (#19606, ext/xmlwriter/php_xmlwriter.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['toMemory', 'toUri', 'toStream'] as $m) {
    echo $m, '=', method_exists(XMLWriter::class, $m) ? '1' : '0', "\n";
}
$w = XMLWriter::toMemory();
$w->startElement('r');
$w->text('x');
$w->endElement();
echo trim($w->outputMemory()), "\n";
$path = sys_get_temp_dir().'/phpc_xmlwriter_to_uri_'.uniqid().'.xml';
$w2 = XMLWriter::toUri($path);
$w2->startElement('u');
$w2->endElement();
$w2->endDocument();
echo trim(file_get_contents($path)), "\n";
@unlink($path);
$stream = fopen('php://memory', 'w+');
$w3 = XMLWriter::toStream($stream);
$w3->startElement('s');
$w3->text('z');
$w3->endElement();
$w3->endDocument();
rewind($stream);
echo trim(stream_get_contents($stream)), "\n";
fclose($stream);
?>
--EXPECT--
toMemory=1
toUri=1
toStream=1
<r>x</r>
<u/>
<s>z</s>
