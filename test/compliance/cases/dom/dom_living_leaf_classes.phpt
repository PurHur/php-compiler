--TEST--
Dom\ living leaf classes — create* / parse / childNodes / attributes (#20948)
--SKIPIF--
<?php
if (!class_exists('Dom\\XMLDocument')) {
    die('skip Dom\\XMLDocument requires PHP_COMPILER_PROFILE=8.4 (#20948)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'Dom\\Text',
    'Dom\\Comment',
    'Dom\\Attr',
    'Dom\\DocumentFragment',
    'Dom\\NamedNodeMap',
    'Dom\\CharacterData',
    'Dom\\CDATASection',
    'Dom\\ProcessingInstruction',
    'Dom\\NodeList',
] as $c) {
    echo $c, '=', class_exists($c) ? 'yes' : 'no', "\n";
}

$doc = Dom\XMLDocument::createFromString('<?xml version="1.0"?><root id="x">hi</root>');
echo 'createText=', get_class($doc->createTextNode('t')), "\n";
echo 'firstChild=', get_class($doc->documentElement->firstChild), "\n";
echo 'childNodes=', get_class($doc->documentElement->childNodes), "\n";
echo 'attributes=', get_class($doc->documentElement->attributes), "\n";
echo 'createComment=', get_class($doc->createComment('c')), "\n";
echo 'createAttr=', get_class($doc->createAttribute('id')), "\n";
echo 'createFrag=', get_class($doc->createDocumentFragment()), "\n";
echo 'createCdata=', get_class($doc->createCDATASection('x')), "\n";
echo 'createPI=', get_class($doc->createProcessingInstruction('pi', 'data')), "\n";
echo 'isa_text=', ($doc->createTextNode('t') instanceof Dom\Text) ? 'yes' : 'no', "\n";
echo 'isa_cdata=', ($doc->createCDATASection('x') instanceof Dom\CharacterData) ? 'yes' : 'no', "\n";

$html = Dom\HTMLDocument::createFromString('<div id="d">hi</div>');
$div = $html->getElementById('d');
echo 'html_text=', get_class($div->firstChild), "\n";
echo 'html_attrs=', get_class($div->attributes), "\n";

$legacy = new DOMDocument();
$legacy->loadXML('<r id="a">t</r>');
echo 'legacyText=', get_class($legacy->createTextNode('t')), "\n";
echo 'legacyChild=', get_class($legacy->documentElement->firstChild), "\n";
echo 'legacyList=', get_class($legacy->documentElement->childNodes), "\n";
echo 'legacyAttrs=', get_class($legacy->documentElement->attributes), "\n";
?>
--EXPECT--
Dom\Text=yes
Dom\Comment=yes
Dom\Attr=yes
Dom\DocumentFragment=yes
Dom\NamedNodeMap=yes
Dom\CharacterData=yes
Dom\CDATASection=yes
Dom\ProcessingInstruction=yes
Dom\NodeList=yes
createText=Dom\Text
firstChild=Dom\Text
childNodes=Dom\NodeList
attributes=Dom\NamedNodeMap
createComment=Dom\Comment
createAttr=Dom\Attr
createFrag=Dom\DocumentFragment
createCdata=Dom\CDATASection
createPI=Dom\ProcessingInstruction
isa_text=yes
isa_cdata=yes
html_text=Dom\Text
html_attrs=Dom\NamedNodeMap
legacyText=DOMText
legacyChild=DOMText
legacyList=DOMNodeList
legacyAttrs=DOMNamedNodeMap
