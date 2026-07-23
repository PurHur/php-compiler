<?php
/**
 * Issue #22630 — XMLReader::XML()/open() instance call returns bool + parser state
 * (re-#19308/#19330; regression from #22288 omitting EX(This) for static methods).
 */
$r = new XMLReader();
var_export($r->XML('<?xml version="1.0"?><root id="1">hi</root>') === true);
echo "\n";
while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT) {
        echo $r->name, '=', $r->getAttribute('id'), "\n";
    }
}

$path = '/tmp/phpc_xmlreader_xml_open.php.xml';
file_put_contents($path, '<?xml version="1.0"?><root id="2">x</root>');
$r2 = new XMLReader();
var_export($r2->open($path) === true);
echo "\n";
while ($r2->read()) {
    if ($r2->nodeType === XMLReader::ELEMENT) {
        echo $r2->name, '=', $r2->getAttribute('id'), "\n";
    }
}

$static = XMLReader::XML('<a id="s"/>');
var_export($static instanceof XMLReader);
echo "\n";
while ($static->read()) {
    if ($static->nodeType === XMLReader::ELEMENT) {
        echo 'static=', $static->name, '=', $static->getAttribute('id'), "\n";
    }
}
