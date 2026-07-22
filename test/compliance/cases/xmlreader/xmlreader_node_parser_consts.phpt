--TEST--
XMLReader node/parser class constants discovery (#22349, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
echo defined('XMLReader::ELEMENT') ? 'Y' : 'N', "\n";
echo defined('XMLReader::END_ELEMENT') ? 'Y' : 'N', "\n";
echo defined('XMLReader::LOADDTD') ? 'Y' : 'N', "\n";
echo constant('XMLReader::ELEMENT'), "\n";
echo constant('XMLReader::SUBST_ENTITIES'), "\n";
echo XMLReader::NONE, "\n";
echo XMLReader::ELEMENT, "\n";
echo XMLReader::END_ELEMENT, "\n";
echo XMLReader::XML_DECLARATION, "\n";
echo XMLReader::LOADDTD, "\n";
echo XMLReader::DEFAULTATTRS, "\n";
echo XMLReader::VALIDATE, "\n";
echo XMLReader::SUBST_ENTITIES, "\n";
$r = new ReflectionClass('XMLReader');
echo $r->getConstant('ELEMENT'), "\n";
echo $r->getConstant('END_ELEMENT'), "\n";
echo $r->getConstant('LOADDTD'), "\n";
$c = $r->getConstants();
ksort($c);
foreach ($c as $name => $value) {
    echo $name, '=', $value, "\n";
}
?>
--EXPECT--
Y
Y
Y
1
4
0
1
15
17
1
2
3
4
1
15
1
ATTRIBUTE=2
CDATA=4
COMMENT=8
DEFAULTATTRS=2
DOC=9
DOC_FRAGMENT=11
DOC_TYPE=10
ELEMENT=1
END_ELEMENT=15
END_ENTITY=16
ENTITY=6
ENTITY_REF=5
LOADDTD=1
NONE=0
NOTATION=12
PI=7
SIGNIFICANT_WHITESPACE=14
SUBST_ENTITIES=4
TEXT=3
VALIDATE=3
WHITESPACE=13
XML_DECLARATION=17
