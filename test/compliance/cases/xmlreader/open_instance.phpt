--TEST--
xmlreader XMLReader::open() instance call — $this/$uri binding (#19330, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
$path = '/tmp/xr_open_instance_compliance.xml';
file_put_contents($path, '<root><a>1</a></root>');
$r = new XMLReader();
$ok = $r->open($path);
echo (int) $ok, "\n";
$names = [];
while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT) {
        $names[] = $r->name;
    }
}
echo implode(',', $names), "\n";
$static = XMLReader::open($path);
$names2 = [];
while ($static->read()) {
    if ($static->nodeType === XMLReader::ELEMENT) {
        $names2[] = $static->name;
    }
}
echo implode(',', $names2), "\n";
?>
--EXPECT--
1
root,a
root,a
