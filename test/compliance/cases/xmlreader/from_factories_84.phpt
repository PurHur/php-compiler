--TEST--
xmlreader XMLReader::fromString/fromUri/fromStream — PHP 8.4 factories (#19607, ext/xmlreader/php_xmlreader.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = XMLReader::fromString('<root><a/></root>');
echo 'read=', $r->read() ? '1' : '0', ' name=', $r->name, "\n";
$path = sys_get_temp_dir().'/phpc_xmlreader_from_uri_'.uniqid().'.xml';
file_put_contents($path, '<doc><b>2</b></doc>');
$r2 = XMLReader::fromUri($path);
$names = [];
while ($r2->read()) {
    if ($r2->nodeType === XMLReader::ELEMENT) {
        $names[] = $r2->name;
    }
}
echo implode(',', $names), "\n";
@unlink($path);
$stream = fopen('php://memory', 'r+');
fwrite($stream, '<s><c/></s>');
rewind($stream);
$r3 = XMLReader::fromStream($stream);
$names3 = [];
while ($r3->read()) {
    if ($r3->nodeType === XMLReader::ELEMENT) {
        $names3[] = $r3->name;
    }
}
fclose($stream);
echo implode(',', $names3), "\n";
?>
--EXPECT--
read=1 name=root
doc,b
s,c
