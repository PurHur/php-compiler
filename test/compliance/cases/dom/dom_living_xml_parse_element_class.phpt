--TEST--
Dom\XMLDocument createFromString/File parsed nodes are Dom\Element (#20856)
--SKIPIF--
<?php
if (!class_exists('Dom\\XMLDocument')) {
    die('skip Dom\\XMLDocument requires PHP_COMPILER_PROFILE=8.4 (#20856)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = Dom\XMLDocument::createFromString(
    '<?xml version="1.0"?><root xmlns:a="urn:a"><a:item id="i">1</a:item></root>'
);
$root = $doc->documentElement;
$child = $root->firstElementChild ?? $root->firstChild;
echo 'root=', get_class($root), "\n";
echo 'child=', get_class($child), "\n";
echo 'created=', get_class($doc->createElement('x')), "\n";
echo 'isa_root=', ($root instanceof Dom\Element) ? 'yes' : 'no', "\n";
echo 'isa_child=', ($child instanceof Dom\Element) ? 'yes' : 'no', "\n";

$path = sys_get_temp_dir() . '/phpc_dom_xml_elclass_' . getmypid() . '.xml';
file_put_contents($path, '<r><c/></r>');
$fileDoc = Dom\XMLDocument::createFromFile($path);
@unlink($path);
echo 'file_root=', get_class($fileDoc->documentElement), "\n";
echo 'file_child=', get_class($fileDoc->documentElement->firstChild), "\n";

$legacy = new DOMDocument();
$legacy->loadXML('<r><c/></r>');
echo 'legacy=', get_class($legacy->documentElement), "\n";
?>
--EXPECT--
root=Dom\Element
child=Dom\Element
created=Dom\Element
isa_root=yes
isa_child=yes
file_root=Dom\Element
file_child=Dom\Element
legacy=DOMElement
