--TEST--
xmlreader XMLReader::open/read/getAttribute — pull parser v1 (#6135, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
file_put_contents('/tmp/xr_compliance.xml', '<root><item id="1">a</item></root>');
$reader = XMLReader::open('/tmp/xr_compliance.xml');
$names = [];
while ($reader->read()) {
    if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'item') {
        $names[] = $reader->getAttribute('id');
    }
}
$reader->close();
echo implode(',', $names), "\n";
echo (int) class_exists('XMLReader'), "\n";
echo XMLReader::ELEMENT, ':', XMLReader::END_ELEMENT, "\n";
?>
--EXPECT--
1
1
1:15
