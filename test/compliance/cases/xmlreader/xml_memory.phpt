--TEST--
xmlreader XMLReader::XML() — in-memory parse (#19308, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
$r = new XMLReader();
$ok = $r->XML('<r><a>1</a></r>');
echo (int) $ok, "\n";
$names = [];
while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT) {
        $names[] = $r->name;
    }
}
echo implode(':', $names), ":\n";
$r2 = XMLReader::XML('<root><b/></root>');
$names2 = [];
while ($r2->read()) {
    if ($r2->nodeType === XMLReader::ELEMENT) {
        $names2[] = $r2->name;
    }
}
echo implode(':', $names2), ":\n";
?>
--EXPECT--
1
r:a:
root:b:
